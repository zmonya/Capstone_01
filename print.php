<?php
// Include the Dompdf autoload file
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Database connection (adjust credentials as needed)
$db = new PDO("mysql:host=localhost;dbname=your_database", "username", "password");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get chart type from request (e.g., via POST or GET)
$chartType = $_REQUEST['chartType'] ?? '';
$chartImage = $_REQUEST['chartImage'] ?? ''; // Base64 image passed from frontend

// Fetch chart data from the database
$chartData = [];
if ($chartType === 'FileUploadTrends') {
    $stmt = $db->query("SELECT upload_day, COUNT(*) as upload_count 
                        FROM uploads 
                        GROUP BY upload_day 
                        ORDER BY upload_day ASC");
    $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($chartType === 'FileDistribution') {
    $stmt = $db->query("SELECT document_type, COUNT(*) as file_count 
                        FROM files 
                        GROUP BY document_type 
                        ORDER BY document_type ASC");
    $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Create a new Dompdf instance
$options = new Options();
$options->set('isRemoteEnabled', true); // Enable remote images (e.g., base64 images)
$dompdf = new Dompdf($options);

// HTML content for the PDF
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($chartType) . ' Report</title>
    <style>
        body { font-family: Arial, sans-serif; }
        h1 { font-size: 18px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        img { max-width: 100%; height: auto; }
    </style>
</head>
<body>
    <h1>' . htmlspecialchars($chartType) . ' Report</h1>
    <img src="' . htmlspecialchars($chartImage) . '" alt="Chart Image">
    <h2>Data Table</h2>
    <table>
        <thead>
            <tr>';

if ($chartType === 'FileUploadTrends') {
    $html .= '<th>Upload Day</th><th>Upload Count</th>';
} elseif ($chartType === 'FileDistribution') {
    $html .= '<th>Document Type</th><th>File Count</th>';
}

$html .= '
            </tr>
        </thead>
        <tbody>';

foreach ($chartData as $row) {
    $html .= '<tr>';
    if ($chartType === 'FileUploadTrends') {
        $html .= '<td>' . htmlspecialchars($row['upload_day']) . '</td><td>' . htmlspecialchars($row['upload_count']) . '</td>';
    } elseif ($chartType === 'FileDistribution') {
        $html .= '<td>' . htmlspecialchars($row['document_type']) . '</td><td>' . htmlspecialchars($row['file_count']) . '</td>';
    }
    $html .= '</tr>';
}

$html .= '
        </tbody>
    </table>
</body>
</html>';

// Load HTML content into Dompdf
$dompdf->loadHtml($html);

// Set paper size and orientation
$dompdf->setPaper('A4', 'portrait');

// Render the PDF
$dompdf->render();

// Output the PDF for printing
$dompdf->stream($chartType . '_Report.pdf', ['Attachment' => false]);
