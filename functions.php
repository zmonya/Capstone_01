<?php
require 'db_connection.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Ensures the user is authenticated and has admin role.
 *
 * @return void
 * @throws Exception If user is not authenticated or not an admin
 */
function requireAdminSession(): void
{
    session_start();
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        header('Location: login.php');
        exit();
    }
}

/**
 * Fetches all top-level cabinets from the files table.
 *
 * @param PDO $pdo Database connection
 * @return array Array of cabinets
 * @throws PDOException If database query fails
 */
function fetchAllCabinets(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT f.File_id AS id, f.File_name AS cabinet_name, f.Meta_data, 
                   COALESCE(d.Department_name, 'No Department') AS department_name
            FROM files f
            LEFT JOIN users_department ud ON f.User_id = ud.User_id
            LEFT JOIN departments d ON ud.Department_id = d.Department_id
            WHERE f.Parent_file_id IS NULL
            AND f.File_status != 'deleted'
            AND f.Meta_data LIKE '%\"cabinet\":%'
            ORDER BY f.File_id DESC
        ");
        $stmt->execute();
        $cabinets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse Meta_data JSON
        foreach ($cabinets as &$cabinet) {
            $meta = json_decode($cabinet['Meta_data'] ?? '{}', true);
            $cabinet['cabinet_name'] = $meta['cabinet'] ?? $cabinet['File_name'];
        }
        unset($cabinet);

        return $cabinets;
    } catch (PDOException $e) {
        error_log("Error in fetchAllCabinets: " . $e->getMessage());
        throw new PDOException("Failed to fetch cabinets.");
    }
}

/**
 * Fetches all departments from the database.
 *
 * @param PDO $pdo Database connection
 * @return array Array of departments
 * @throws PDOException If database query fails
 */
function fetchAllDepartments(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT Department_id AS id, Department_name AS name 
            FROM departments 
            ORDER BY Department_name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in fetchAllDepartments: " . $e->getMessage());
        throw new PDOException("Failed to fetch departments.");
    }
}

/**
 * Fetches storage locations (layers/boxes/folders) for a cabinet.
 *
 * @param PDO $pdo Database connection
 * @param int $cabinetId Cabinet File_id
 * @return array Organized storage locations
 * @throws Exception If cabinet ID is invalid or query fails
 */
function fetchStorageLocations(PDO $pdo, int $cabinetId): array
{
    if (!is_numeric($cabinetId) || $cabinetId <= 0) {
        throw new Exception("Invalid cabinet ID");
    }

    try {
        $stmt = $pdo->prepare("
            SELECT f.File_id AS id, f.File_name, f.Meta_data, f.User_id, 
                   COALESCE(u.Username, 'Unknown User') AS uploader,
                   COALESCE(dtf.Field_name, 'Unknown Type') AS document_category
            FROM files f
            LEFT JOIN users u ON f.User_id = u.User_id
            LEFT JOIN documents_type_fields dtf ON f.Document_type_id = dtf.Document_type_id
            WHERE f.Parent_file_id = ?
            AND f.File_status != 'deleted'
            ORDER BY f.File_id
        ");
        $stmt->execute([$cabinetId]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $organized = [];
        foreach ($locations as $loc) {
            $meta = json_decode($loc['Meta_data'] ?? '{}', true);
            $layer = (int)($meta['layer'] ?? 0);
            $box = (int)($meta['box'] ?? 0);
            $folder = (int)($meta['folder'] ?? 0);

            if (!isset($organized[$layer][$box][$folder])) {
                $organized[$layer][$box][$folder] = [
                    'id' => $loc['id'],
                    'is_occupied' => !empty($loc['File_name']),
                    'file_count' => 0,
                    'folder_capacity' => null, // Not stored in schema; can be added to Meta_data
                    'files' => []
                ];
            }

            if ($loc['File_name']) {
                $organized[$layer][$box][$folder]['files'][] = [
                    'file_id' => $loc['id'],
                    'file_name' => $loc['File_name'],
                    'uploader' => $loc['uploader'],
                    'document_category' => $loc['document_category']
                ];
                $organized[$layer][$box][$folder]['file_count']++;
            }
        }

        return $organized;
    } catch (PDOException $e) {
        error_log("Error in fetchStorageLocations: " . $e->getMessage());
        throw new PDOException("Failed to fetch storage locations.");
    }
}
