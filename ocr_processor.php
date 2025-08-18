<?php
require 'db_connection.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Element\Text as TextElement;
use PhpOffice\PhpWord\Element\TextRun;

function extractTextFromFile(string $filePath, string $fileType): ?string
{
    try {
        $filePath = realpath(__DIR__ . '/' . $filePath);
        if (!$filePath || !file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("File not found or not readable: $filePath");
        }

        switch ($fileType) {
            case 'pdf':
            case 'png':
            case 'jpg':
            case 'jpeg':
                $tesseractPath = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? '"' . __DIR__ . '\tesseract\tesseract.exe' . '"'
                    : 'tesseract';

                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && !file_exists(__DIR__ . '\tesseract\tesseract.exe')) {
                    throw new Exception("Tesseract executable not found.");
                }

                $outputFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ocr_') . '.txt';
                $command = escapeshellcmd(
                    "$tesseractPath " .
                        escapeshellarg($filePath) . " " .
                        escapeshellarg(str_replace('.txt', '', $outputFile)) . " -l eng"
                );

                exec($command . ' 2>&1', $output, $returnCode);

                if ($returnCode !== 0) {
                    throw new Exception("Tesseract OCR failed with code $returnCode: " . implode("\n", $output));
                }

                $text = file_get_contents($outputFile);
                unlink($outputFile);

                return $text ?: null;

            case 'txt':
                return file_get_contents($filePath) ?: null;

            case 'csv':
            case 'xlsx':
                $spreadsheet = IOFactory::load($filePath);
                $text = '';
                foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $value = $cell->getValue();
                        $text .= is_null($value) ? '' : $value . ' ';
                    }
                    $text .= "\n";
                }
                return $text ?: null;

            case 'docx':
                $phpWord = WordIOFactory::load($filePath, 'Word2007');
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if ($element instanceof TextElement) {
                            $text .= $element->getText() . ' ';
                        } elseif ($element instanceof TextRun) {
                            foreach ($element->getElements() as $subElement) {
                                if ($subElement instanceof TextElement) {
                                    $text .= $subElement->getText() . ' ';
                                }
                            }
                        }
                    }
                }
                return $text ?: null;

            default:
                throw new Exception("Unsupported file type: $fileType");
        }
    } catch (Exception $e) {
        error_log("OCR error for $filePath (type: $fileType): " . $e->getMessage(), 3, __DIR__ . '/logs/ocr_processor.log');
        return null;
    }
}

try {
    global $pdo;
    $fileId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
    if (!$fileId) {
        $stmt = $pdo->prepare("SELECT file_id, file_path, file_type FROM files WHERE file_status = 'pending_ocr' LIMIT 1");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("SELECT file_id, file_path, file_type FROM files WHERE file_id = ? AND file_status = 'pending_ocr'");
        $stmt->execute([$fileId]);
    }

    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        error_log("No pending OCR files found.", 3, __DIR__ . '/logs/ocr_processor.log');
        exit("No pending OCR files found.\n");
    }

    // Check retry attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) as attempts FROM transactions WHERE file_id = ? AND transaction_type IN ('ocr_process', 'ocr_retry') AND transaction_status = 'failed'");
    $stmt->execute([$file['file_id']]);
    $attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempts'];
    if ($attempts >= 3) {
        error_log("Max OCR retries reached for file ID {$file['file_id']}", 3, __DIR__ . '/logs/ocr_processor.log');
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'ocr_failed' WHERE file_id = ?");
        $stmt->execute([$file['file_id']]);
        exit("Max OCR retries reached for file ID {$file['file_id']}.\n");
    }

    // Validate file_type
    $validTypes = ['pdf', 'docx', 'txt', 'png', 'jpg', 'jpeg', 'csv', 'xlsx'];
    if (!in_array($file['file_type'], $validTypes)) {
        error_log("Invalid file type for file ID {$file['file_id']}: {$file['file_type']}", 3, __DIR__ . '/logs/ocr_processor.log');
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'ocr_failed' WHERE file_id = ?");
        $stmt->execute([$file['file_id']]);
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
        ");
        $stmt->execute([null, $file['file_id'], "Invalid file type for file ID {$file['file_id']}"]);
        exit("Invalid file type: {$file['file_type']}\n");
    }

    // Check file accessibility
    $filePath = realpath(__DIR__ . '/' . $file['file_path']);
    if (!$filePath || !is_readable($filePath)) {
        error_log("File not accessible for file ID {$file['file_id']}: {$file['file_path']}", 3, __DIR__ . '/logs/ocr_processor.log');
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'ocr_failed' WHERE file_id = ?");
        $stmt->execute([$file['file_id']]);
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
        ");
        $stmt->execute([null, $file['file_id'], "File not accessible for file ID {$file['file_id']}"]);
        exit("File not accessible: {$file['file_path']}\n");
    }

    $text = extractTextFromFile($file['file_path'], $file['file_type']);
    if (is_null($text) || $text === '') {
        error_log("No text extracted for file ID {$file['file_id']} (path: {$file['file_path']}): " . (is_null($text) ? 'OCR failed to produce output' : 'Extracted text is empty'), 3, __DIR__ . '/logs/ocr_processor.log');
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'ocr_failed' WHERE file_id = ?");
        $stmt->execute([$file['file_id']]);
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
        ");
        $stmt->execute([null, $file['file_id'], "OCR failed for file ID {$file['file_id']}"]);
        exit("No text extracted for file ID {$file['file_id']}.\n");
    }

    // Insert or update text_repository
    $stmt = $pdo->prepare("
        INSERT INTO text_repository (file_id, extracted_text)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE extracted_text = ?
    ");
    $stmt->execute([$file['file_id'], $text, $text]);

    $stmt = $pdo->prepare("UPDATE files SET file_status = 'ocr_complete' WHERE file_id = ?");
    $stmt->execute([$file['file_id']]);

    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
        VALUES (?, ?, 'ocr_process', 'completed', NOW(), ?)
    ");
    $stmt->execute([null, $file['file_id'], "OCR processed for file ID {$file['file_id']}"]);

    error_log("OCR completed for file ID {$file['file_id']} (path: {$file['file_path']})", 3, __DIR__ . '/logs/ocr_processor.log');
} catch (Exception $e) {
    error_log("OCR processing error for file ID " . ($file['file_id'] ?? 'unknown') . ": " . $e->getMessage() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString(), 3, __DIR__ . '/logs/ocr_processor.log');
    if (isset($file['file_id'])) {
        $stmt = $pdo->prepare("UPDATE files SET file_status = 'ocr_failed' WHERE file_id = ?");
        $stmt->execute([$file['file_id']]);
        $stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, file_id, transaction_type, transaction_status, transaction_time, description)
            VALUES (?, ?, 'ocr_process', 'failed', NOW(), ?)
        ");
        $stmt->execute([null, $file['file_id'], "OCR failed for file ID {$file['file_id']}: {$e->getMessage()}"]);
    }
    exit("OCR processing error: " . $e->getMessage() . "\n");
}
