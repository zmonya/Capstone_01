<?php
// Include the Dompdf autoload file
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Decode the incoming JSON data
$data = json_decode(file_get_contents('php://input'), true);

$chartType = $data['chartType'];
$chartData = $data['chartData'];
$chartImage = $data['chartImage'];

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
    <title>' . $chartType . ' Report</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
        }
        h1 { 
            font-size: 18px; 
            font-weight: bold; 
            text-align: center; 
        }
        h2 {
            font-size: 14px;
            margin-top: 20px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
            border: 1px solid #000000; /* Explicitly set table border */
        }
        th, td { 
            border: 1px solid #000000 !important; /* Force border with !important */
            padding: 8px; 
            text-align: left; 
        }
        th { 
            background-color: #f2f2f2; 
            font-weight: bold; 
        }
        img { 
            max-width: 100%; 
            height: auto; 
            display: block; 
            margin: 10px auto; 
        }
    </style>
</head>
<body>
    <h1>' . $chartType . ' Report</h1>
    <img src="' . $chartImage . '" alt="Chart Image">
    <h2>Data Table</h2>
    <table>
        <thead>
            <tr>';

if ($chartType === 'FileUploadTrends') {
    $html .= '<th>Upload Day</th><th>Upload Count</th>';
} else if ($chartType === 'FileDistribution') {
    $html .= '<th>Document Type</th><th>File Count</th>';
}

$html .= '
            </tr>
        </thead>
        <tbody>';

foreach ($chartData as $row) {
    $html .= '<tr>';
    if ($chartType === 'FileUploadTrends') {
        $html .= '<td>' . $row['upload_day'] . '</td><td>' . $row['upload_count'] . '</td>';
    } else if ($chartType === 'FileDistribution') {
        $html .= '<td>' . $row['document_type'] . '</td><td>' . $row['file_count'] . '</td>';
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

// Output the PDF for download
$dompdf->stream($chartType . '_Report.pdf', ['Attachment' => true]);
