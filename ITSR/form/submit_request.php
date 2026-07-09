<?php

declare(strict_types=1);

// Suppress PHP error output on public-facing pages
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/login/auth.php';
require_once dirname(__DIR__) . '/includes/workflow.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

$returnContext = strtolower(trim((string) ($_POST['return_context'] ?? 'department')));
if (!in_array($returnContext, ['admin', 'staff', 'department'], true)) {
    $returnContext = 'department';
}

$formReturnUrl = 'request_form.php?return=' . rawurlencode($returnContext);
$portalReturnUrl = match ($returnContext) {
    'admin' => '../admin/dashboard.php',
    'staff' => '../staff/dashboard.php',
    'department' => (currentUserRole() === 'department_user') ? '../department/dashboard.php' : '../login/login.php?portal=department',
    default => (currentUserRole() === 'department_user') ? '../department/dashboard.php' : '../login/login.php?portal=department',
};
$portalReturnLabel = match ($returnContext) {
    'admin' => 'Back to Admin',
    'staff' => 'Back to Staff',
    'department' => (currentUserRole() === 'department_user') ? 'Back to Department' : 'Back To Log In',
    default => (currentUserRole() === 'department_user') ? 'Back to Department' : 'Back To Log In',
};

function field(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function signatureField(string $key): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    $value = preg_replace('/\s+/', ' ', $value) ?? '';

    if ($value === '') {
        return '';
    }

    if ($value !== strip_tags($value) || preg_match('/[<>]/', $value)) {
        throw new InvalidArgumentException('Electronic Signature cannot contain HTML or script characters.');
    }

    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > 100) {
        throw new InvalidArgumentException('Electronic Signature must be 100 characters or fewer.');
    }

    return $value;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatText(string $value): string
{
    $trimmed = trim($value);

    if ($trimmed === '') {
        return '&nbsp;';
    }

    return nl2br(escape($trimmed));
}

function formatDateValue(string $value): string
{
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d/m/Y', $timestamp) : $value;
}

function normalizeDateForDb(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function drawCellRow(TCPDF $pdf, float $x, float $y, array $widths, float $height, array $values, array $styles = []): void
{
    $currentX = $x;

    foreach ($widths as $index => $width) {
        $style = $styles[$index] ?? [];
        $fontStyle = $style['fontStyle'] ?? '';
        $align = $style['align'] ?? 'L';
        $fill = $style['fill'] ?? false;
        $fillColor = $style['fillColor'] ?? [255, 255, 255];
        $fontSize = $style['fontSize'] ?? 9;
        $valign = $style['valign'] ?? 'M';

        $pdf->SetXY($currentX, $y);
        $pdf->SetFont('helvetica', $fontStyle, $fontSize);
        if ($fill) {
            $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
        }
        $pdf->MultiCell($width, $height, (string) ($values[$index] ?? ''), 1, $align, $fill, 0, '', '', true, 0, false, true, $height, $valign);
        $currentX += $width;
    }
}

function signaturePdfFont(TCPDF $pdf): string
{
    static $fontName = null;

    if ($fontName !== null) {
        return $fontName;
    }

    $fontName = 'helvetica';
    $fontStorageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'fonts';
    $fontCandidates = [
        __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'GreatVibes-Regular.ttf',
        __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'Allura-Regular.ttf',
        __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'DancingScript-Regular.ttf',
        'C:\\Windows\\Fonts\\segoesc.ttf',
        'C:\\Windows\\Fonts\\BRUSHSCI.TTF',
    ];

    if (!is_dir($fontStorageDir) && !mkdir($fontStorageDir, 0777, true) && !is_dir($fontStorageDir)) {
        return $fontName;
    }

    foreach ($fontCandidates as $fontPath) {
        if (!is_file($fontPath)) {
            continue;
        }

        $outPath = rtrim($fontStorageDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $generatedFontName = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 32, $outPath);

        if ($generatedFontName === false) {
            continue;
        }

        $definitionPath = $outPath . $generatedFontName . '.php';
        if (!is_file($definitionPath)) {
            continue;
        }

        $pdf->AddFont($generatedFontName, '', $definitionPath);
        $fontName = $generatedFontName;
        break;
    }

    return $fontName;
}

function placeElectronicSignature(TCPDF $pdf, float $x, float $y, float $w, float $h, string $name): void
{
    $name = trim($name);

    if ($name === '') {
        return;
    }

    $fontName = signaturePdfFont($pdf);
    $pdf->SetXY($x, $y);
    $pdf->SetFont($fontName, $fontName === 'helvetica' ? 'I' : '', 8.8);
    $pdf->MultiCell($w, $h, $name, 0, 'C', false, 1, '', '', true, 0, false, true, $h, 'M');
}

function drawServiceCheck(TCPDF $pdf, float $x, float $y, bool $selected): void
{
    $pdf->Rect($x, $y, 3.5, 3.5);

    if (!$selected) {
        return;
    }

    $pdf->SetFont('helvetica', 'B', 8.4);
    $pdf->SetXY($x - 0.1, $y - 0.15);
    $pdf->Cell(3.7, 3.7, '/', 0, 0, 'C');
    $pdf->SetFont('helvetica', '', 8);
}

function drawWrappedText(TCPDF $pdf, float $x, float $y, float $w, float $h, string $text, bool $bold = false): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('helvetica', $bold ? 'B' : '', $bold ? 10 : 9);
    $pdf->MultiCell($w, $h, $text, 0, 'L', false, 1, '', '', true, 0, false, true, $h, 'T');
}

function fieldText(string $key): string
{
    $value = field($key);
    return $value === '' ? ' ' : $value;
}

function isSection2Empty(): bool
{
    $fields = ['assign_to', 'date_receive', 'date_complete', 'total_hour_taken', 'corrective_action'];

    foreach ($fields as $fieldName) {
        if (trim((string) ($_POST[$fieldName] ?? '')) !== '') {
            return false;
        }
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

// CSRF protection for the public form
if (!csrfValidate()) {
    http_response_code(403);
    $formReturnUrlHtml = htmlspecialchars($formReturnUrl, ENT_QUOTES, 'UTF-8');
    $portalReturnUrlHtml = htmlspecialchars($portalReturnUrl, ENT_QUOTES, 'UTF-8');
    $portalReturnLabelHtml = htmlspecialchars($portalReturnLabel, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Security Token Expired</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#20324a;padding:24px}.box{max-width:620px;width:100%;background:#fff;border:1px solid #dbe4ee;border-radius:18px;box-shadow:0 20px 40px rgba(15,23,42,.08);padding:28px 30px}h1{margin:0 0 10px;font-size:24px}p{margin:0 0 18px;line-height:1.6;color:#52667d}.actions{display:flex;gap:12px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:999px;text-decoration:none;font-weight:700;border:1px solid #c9d6e4}.btn.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.btn.secondary{background:#fff;color:#20324a}</style></head><body><div class="box"><h1>Security token expired</h1><p>Your request form was submitted from an old page or a session that has changed. Go back, reload the form, and submit again.</p><div class="actions"><a class="btn primary" href="' . $formReturnUrlHtml . '">Back to Form</a><a class="btn secondary" href="' . $portalReturnUrlHtml . '">' . $portalReturnLabelHtml . '</a></div></div></body></html>';
    exit;
}

ensureItsrWorkflowSchema();
try {
    validateRequestAttachmentUpload('qa_approval_document');
    validateRequestAttachmentUpload('supporting_document');
} catch (Throwable $exception) {
    http_response_code(400);
    echo escape($exception->getMessage());
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists('TCPDF')) {
    http_response_code(500);
    echo 'TCPDF library not found. Run composer install.';
    exit;
}


$pdfStorageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'pdfs';

if (!is_dir($pdfStorageDir) && !mkdir($pdfStorageDir, 0777, true) && !is_dir($pdfStorageDir)) {
    http_response_code(500);
    echo 'Unable to create PDF storage folder.';
    exit;
}

$serviceTypes = $_POST['service_types'] ?? [];
if (!is_array($serviceTypes)) {
    $serviceTypes = [];
}

$serviceOptions = [
    'Hardware',
    'Software',
    'AMROS',
    'SOLO',
    'Internet',
    'Email',
    'Training',
    'GP/AIS/FIS',
    'Others',
];

$selectedServiceTypes = array_map('strval', $serviceTypes);
$otherService = field('other_service_specify');

$serverRequestDate = date('Y-m-d');
$requestDate = formatDateValue($serverRequestDate);
$dateReceive = '';
$dateComplete = '';

try {
    $requestorSignatureName = signatureField('requestor_signature_name');
    $approverSignatureName = signatureField('approver_signature_name');
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo escape($exception->getMessage());
    exit;
}

$signedAt = date('Y-m-d H:i:s');
$requestorSignedAt = $requestorSignatureName !== '' ? $signedAt : null;
$approverSignedAt = $approverSignatureName !== '' ? $signedAt : null;
$staffSignatureName = '';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Enterprise Request Form');
$pdf->SetAuthor('Enterprise');
$pdf->SetTitle('IT Service Request Form');
$pdf->SetSubject('IT Service Request');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false, 0);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetTextColor(20, 20, 20);

$section2Empty = isSection2Empty();

$logoPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'logo.png';
$pdf->SetLineWidth(0.12);
$pdf->SetDrawColor(112, 112, 112);
$pdf->SetTextColor(35, 35, 35);
$pdf->SetFont('helvetica', '', 8);

$left = 20;
$right = 190;
$top = 18;
$width = $right - $left;

if (is_file($logoPath)) {
    $pdf->Image($logoPath, $left + 2, $top + 1.2, 35, 12, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);
}

$pdf->SetFont('helvetica', 'B', 12.8);
$pdf->SetXY($left + 55, $top + 3.6);
$pdf->Cell(92, 6, 'IT SERVICE REQUEST FORM', 0, 0, 'C');
$pdf->Line($left, $top + 17, $right - 4, $top + 17);

$pdf->SetFont('helvetica', 'B', 8.7);
$pdf->SetXY($left + 2, $top + 20);
$pdf->Cell(100, 5, 'MANAGEMENT INFORMATION SYSTEM DEPARTMENT', 0, 0, 'L');

$y = $top + 28;
$pdf->Rect($left, $y, $width, 96);
$pdf->SetFont('helvetica', 'B', 8.2);
$pdf->SetXY($left + 1.5, $y + 1.2);
$pdf->Cell(90, 4, 'SECTION 1. TO BE COMPLETED BY REQUESTOR', 0, 0, 'L');

$y1 = $y + 7;
drawCellRow($pdf, $left, $y1, [30, 140], 6, ['Company', fieldText('company')], [['fontStyle' => 'B'], []]);
$y1 += 6;
drawCellRow($pdf, $left, $y1, [30, 140], 6, ['Department', fieldText('department')], [['fontStyle' => 'B'], []]);
$y1 += 6;
drawCellRow($pdf, $left, $y1, [30, 140], 6, ['Date', $requestDate === '' ? ' ' : $requestDate], [['fontStyle' => 'B'], []]);
$y1 += 6;

drawCellRow($pdf, $left, $y1, [30, 55, 35, 50], 6, ['', 'Name', 'Signature', 'Phone (HP/Ext.)'], [['fontStyle' => 'B'], ['fontStyle' => 'B'], ['fontStyle' => 'B'], ['fontStyle' => 'B']]);
$y1 += 6;

$personHeight = 8.5;
drawCellRow($pdf, $left, $y1, [30, 55, 35, 50], $personHeight, ['Requestor', fieldText('requestor_name'), '', fieldText('requestor_phone')], [['fontStyle' => 'B'], [], [], []]);
placeElectronicSignature($pdf, $left + 85.8, $y1 + 0.3, 34, 8.1, $requestorSignatureName);
$y1 += $personHeight;

drawCellRow($pdf, $left, $y1, [30, 55, 35, 50], $personHeight, ['Approved by', fieldText('approved_by_name'), '', fieldText('approved_by_phone')], [['fontStyle' => 'B'], [], [], []]);
placeElectronicSignature($pdf, $left + 85.8, $y1 + 0.3, 34, 8.1, $approverSignatureName);
$y1 += $personHeight;

$serviceY = $y1;
$pdf->Rect($left, $serviceY, $width, 21);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetXY($left + 1.5, $serviceY + 1.2);
$pdf->Cell(110, 4, 'Type of Service Request (tick ( / ) which applicable)', 0, 0, 'L');

$serviceLayout = [
    ['Hardware', $left + 2, $serviceY + 6.4],
    ['Software', $left + 39, $serviceY + 6.4],
    ['AMROS', $left + 76, $serviceY + 6.4],
    ['SOLO', $left + 113, $serviceY + 6.4],
    ['Internet', $left + 2, $serviceY + 11.8],
    ['Email', $left + 39, $serviceY + 11.8],
    ['Training', $left + 76, $serviceY + 11.8],
    ['GP/AIS/FIS', $left + 113, $serviceY + 11.8],
    ['Others', $left + 2, $serviceY + 17.1],
];

$pdf->SetFont('helvetica', '', 8);
foreach ($serviceLayout as [$label, $xBox, $yBox]) {
    drawServiceCheck($pdf, $xBox + 28, $yBox, in_array($label, $selectedServiceTypes, true));
    $pdf->SetXY($xBox, $yBox - 0.2);
    $pdf->Cell(26, 4, $label, 0, 0, 'L');
}

$pdf->SetFont('helvetica', '', 6.8);
$pdf->SetXY($left + 39, $serviceY + 17.2);
$pdf->Cell(20, 3.5, 'Please specify', 0, 0, 'L');
$pdf->Line($left + 60, $serviceY + 19.8, $right - 2, $serviceY + 19.8);
$pdf->SetXY($left + 61, $serviceY + 16.8);
$pdf->Cell(90, 3.5, fieldText('other_service_specify'), 0, 0, 'L');

$locY = $serviceY + 21;
drawCellRow($pdf, $left, $locY, [85, 85], 6, ['Location', 'IP Address/Tag No.'], [['fontStyle' => 'B'], ['fontStyle' => 'B']]);
$locY += 6;
drawCellRow($pdf, $left, $locY, [85, 85], 6, [fieldText('location'), fieldText('ip_tag_no')]);
$locY += 6;

$noteHeight = 15;
$pdf->Rect($left, $locY, $width, $noteHeight);
$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetXY($left + 1.5, $locY + 1);
$pdf->Cell(15, 3, 'Note:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 6.0);
$pdf->SetXY($left + 1.5, $locY + 3.8);
$pdf->MultiCell($width - 3, 3.5,
    "1. New request of email and AMROS ID, please state the full name of the user, and approved by the HOD or related approval personnel.\n" .
    "2. Please specify the hardware location.\n" .
    "3. Any problem relates with PC, please provide the IP Address or Tag No.",
    0, 'L', false, 1, '', '', true, 0, false, true, $noteHeight - 5, 'T'
);

$descY = $locY + $noteHeight;
$pdf->Rect($left, $descY, $width, 12);
$pdf->SetFont('helvetica', 'B', 7.8);
$pdf->SetXY($left + 1.5, $descY + 1.4);
$pdf->MultiCell($width - 3, 3.7, 'Specify any Special Qualifications for this Service Required / Description of Problem & Justification of Service', 0, 'L', false, 1, '', '', true, 0, false, true, 8, 'T');
$pdf->SetFont('helvetica', '', 6.8);
$pdf->SetXY($left + 1.5, $descY + 6.5);
$pdf->Cell(60, 3, '(use attachment if necessary)', 0, 0, 'L');

$linedY = $descY + 12;
$linedHeight = 58;
$pdf->Rect($left, $linedY, $width, $linedHeight);
for ($lineY = $linedY + 6; $lineY < $linedY + $linedHeight; $lineY += 6) {
    $pdf->Line($left, $lineY, $right, $lineY);
}
$pdf->SetFont('helvetica', '', 6.5);
$pdf->SetXY($left + 1.5, $linedY + 1.5);
$pdf->MultiCell($width - 3, 5.6, field('problem_description'), 0, 'L', false, 1, '', '', true, 0, false, true, $linedHeight - 3, 'T');

$section2Y = $linedY + $linedHeight;
$pdf->Rect($left, $section2Y, $width, 39);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->SetXY($left + 1.5, $section2Y + 1.2);
$pdf->Cell(120, 4, 'SECTION 2. TO BE COMPLETED BY MANAGEMENT INFORMATION SYSTEM DEPARTMENT', 0, 0, 'L');

$section2RowY = $section2Y + 7;
drawCellRow($pdf, $left, $section2RowY, [28, 28, 28, 28, 58], 6, ['Assign To', 'Date Receive', 'Date Complete', 'Total Hour Taken', 'Signature'], [['fontStyle' => 'B'], ['fontStyle' => 'B'], ['fontStyle' => 'B'], ['fontStyle' => 'B'], ['fontStyle' => 'B']]);
$section2RowY += 6;
drawCellRow(
    $pdf,
    $left,
    $section2RowY,
    [28, 28, 28, 28, 58],
    8,
    $section2Empty
        ? [' ', ' ', ' ', ' ', ' ']
        : [fieldText('assign_to'), $dateReceive === '' ? ' ' : $dateReceive, $dateComplete === '' ? ' ' : $dateComplete, fieldText('total_hour_taken'), '']
);
if (!$section2Empty) {
    placeElectronicSignature($pdf, $left + 114.7, $section2RowY + 0.25, 36, 7, $staffSignatureName);
}
$section2RowY += 8;

$pdf->Rect($left, $section2RowY, $width, 6);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetXY($left + 1.5, $section2RowY + 1.2);
$pdf->Cell(60, 3, 'Corrective Action / Solution', 0, 0, 'L');
$section2RowY += 6;

$correctiveHeight = 18;
$pdf->Rect($left, $section2RowY, $width, $correctiveHeight);
for ($lineY = $section2RowY + 6; $lineY < $section2RowY + $correctiveHeight; $lineY += 6) {
    $pdf->Line($left, $lineY, $right, $lineY);
}
$pdf->SetFont('helvetica', '', 6.4);
$pdf->SetXY($left + 1.5, $section2RowY + 1.5);
$pdf->MultiCell($width - 3, 5.5, $section2Empty ? '' : field('corrective_action'), 0, 'L', false, 1, '', '', true, 0, false, true, $correctiveHeight - 3, 'T');

$footerY = 282.8;
$pdf->SetFont('helvetica', '', 6);
$pdf->SetXY($left, $footerY);
$pdf->Cell(85, 3, 'MIS/03-01/SRF', 0, 0, 'L');
$pdf->Cell(85, 3, 'Rev 6 25/05/2025', 0, 0, 'L');

$filename = 'it_service_request_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
$filePath = $pdfStorageDir . DIRECTORY_SEPARATOR . $filename;
$pdf->Output($filePath, 'F');

$pdfRelativePath = 'storage/pdfs/' . $filename;
$downloadUrl = '';

try {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $isUpdate = false;
    if ($requestId > 0) {
        $pdo = db();
        ensureItsrWorkflowSchema();
        
        $deptFilter = '';
        $params = [':id' => $requestId];
        if (currentUserRole() === 'department_user') {
            $deptFilter = 'AND department = :department';
            $params[':department'] = currentUserDepartment();
        }
        
        $stmt = $pdo->prepare("SELECT id, status FROM service_requests WHERE id = :id AND status = 'returned' $deptFilter LIMIT 1");
        $stmt->execute($params);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $isUpdate = true;
        }
    }

    if ($isUpdate) {
        $stmt = db()->prepare(
            'UPDATE service_requests SET
                company = :company,
                department = :department,
                requestor_email = :requestor_email,
                request_date = :request_date,
                requestor_name = :requestor_name,
                requestor_phone = :requestor_phone,
                requestor_signature_name = :requestor_signature_name,
                requestor_signed_at = :requestor_signed_at,
                approved_by_name = :approved_by_name,
                approved_by_phone = :approved_by_phone,
                approver_signature_name = :approver_signature_name,
                approver_signed_at = :approver_signed_at,
                service_types = :service_types,
                other_service_specify = :other_service_specify,
                location = :location,
                ip_tag_no = :ip_tag_no,
                problem_description = :problem_description,
                corrective_action = :corrective_action,
                pdf_path = :pdf_path,
                status = \'resubmitted\',
                resubmitted_at = NOW(),
                latest_user_update_at = NOW()
            WHERE id = :id'
        );
        
        $stmt->execute([
            ':company' => field('company'),
            ':department' => field('department'),
            ':requestor_email' => field('requestor_email'),
            ':request_date' => $serverRequestDate,
            ':requestor_name' => field('requestor_name'),
            ':requestor_phone' => field('requestor_phone'),
            ':requestor_signature_name' => $requestorSignatureName,
            ':requestor_signed_at' => $requestorSignedAt,
            ':approved_by_name' => field('approved_by_name'),
            ':approved_by_phone' => field('approved_by_phone'),
            ':approver_signature_name' => $approverSignatureName,
            ':approver_signed_at' => $approverSignedAt,
            ':service_types' => json_encode($selectedServiceTypes, JSON_UNESCAPED_UNICODE),
            ':other_service_specify' => field('other_service_specify'),
            ':location' => field('location'),
            ':ip_tag_no' => field('ip_tag_no'),
            ':problem_description' => field('problem_description'),
            ':corrective_action' => field('corrective_action'),
            ':pdf_path' => $pdfRelativePath,
            ':id' => $requestId,
        ]);
        
        // Also update latest request_returns record to set resubmitted_at
        $updateReturnStmt = db()->prepare(
            'UPDATE request_returns SET resubmitted_at = NOW() WHERE request_id = :request_id AND resubmitted_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $updateReturnStmt->execute([':request_id' => $requestId]);
        
        storeRequestAttachment('qa_approval_document', 'qa_approval', $requestId);
        storeRequestAttachment('supporting_document', 'supporting_document', $requestId);
        
        addRequestLog($requestId, null, 'request_resubmitted', 'Request resubmitted by department with updates.');
        logSystemActivity(
            'request_updated',
            'Service request resubmitted',
            'Request #' . $requestId . ' was resubmitted by ' . field('requestor_name')
        );

        // Notify admin (optional: future enhancement) — resubmission logged but no separate email for now
    } else {
        $stmt = db()->prepare(
            'INSERT INTO service_requests (
                company,
                department,
                requestor_email,
                request_date,
                requestor_name,
                requestor_phone,
                requestor_signature_name,
                requestor_signed_at,
                requestor_signature_path,
                approved_by_name,
                approved_by_phone,
                approver_signature_name,
                approver_signed_at,
                approved_by_signature_path,
                service_types,
                other_service_specify,
                location,
                ip_tag_no,
                problem_description,
                assign_to,
                date_receive,
                date_complete,
                total_hour_taken,
                staff_signature_path,
                corrective_action,
                pdf_path
            ) VALUES (
                :company,
                :department,
                :requestor_email,
                :request_date,
                :requestor_name,
                :requestor_phone,
                :requestor_signature_name,
                :requestor_signed_at,
                :requestor_signature_path,
                :approved_by_name,
                :approved_by_phone,
                :approver_signature_name,
                :approver_signed_at,
                :approved_by_signature_path,
                :service_types,
                :other_service_specify,
                :location,
                :ip_tag_no,
                :problem_description,
                :assign_to,
                :date_receive,
                :date_complete,
                :total_hour_taken,
                :staff_signature_path,
                :corrective_action,
                :pdf_path
            )'
        );

        $stmt->execute([
            ':company' => field('company'),
            ':department' => field('department'),
            ':requestor_email' => field('requestor_email'),
            ':request_date' => $serverRequestDate,
            ':requestor_name' => field('requestor_name'),
            ':requestor_phone' => field('requestor_phone'),
            ':requestor_signature_name' => $requestorSignatureName,
            ':requestor_signed_at' => $requestorSignedAt,
            ':requestor_signature_path' => null,
            ':approved_by_name' => field('approved_by_name'),
            ':approved_by_phone' => field('approved_by_phone'),
            ':approver_signature_name' => $approverSignatureName,
            ':approver_signed_at' => $approverSignedAt,
            ':approved_by_signature_path' => null,
            ':service_types' => json_encode($selectedServiceTypes, JSON_UNESCAPED_UNICODE),
            ':other_service_specify' => field('other_service_specify'),
            ':location' => field('location'),
            ':ip_tag_no' => field('ip_tag_no'),
            ':problem_description' => field('problem_description'),
            ':assign_to' => field('assign_to'),
            ':date_receive' => null,
            ':date_complete' => null,
            ':total_hour_taken' => '',
            ':staff_signature_path' => null,
            ':corrective_action' => field('corrective_action'),
            ':pdf_path' => $pdfRelativePath,
        ]);

        $requestId = (int) db()->lastInsertId();
        storeRequestAttachment('qa_approval_document', 'qa_approval', $requestId);
        storeRequestAttachment('supporting_document', 'supporting_document', $requestId);
        addRequestLog($requestId, null, 'request_submitted', 'Request submitted by ' . field('requestor_name') . '.');
        logSystemActivity(
            'request_created',
            'New service request submitted',
            'Request #' . $requestId . ' was submitted by ' . field('requestor_name')
        );

        // Feature 1: Send submission confirmation email (fire-and-forget, non-blocking)
        try {
            $freshRequest = db()->prepare('SELECT * FROM service_requests WHERE id = :id LIMIT 1');
            $freshRequest->execute([':id' => $requestId]);
            $newRequestRow = $freshRequest->fetch();
            if ($newRequestRow) {
                itsrNotifyRequestorSubmission($newRequestRow);
            }
        } catch (Throwable $emailEx) {
            error_log('Submission email failed for request #' . $requestId . ': ' . $emailEx->getMessage());
        }
    }
} catch (Throwable $exception) {
    appLogException($exception, 'Request submission persistence failed');
    http_response_code(500);
    echo 'The PDF was created, but the request could not be fully saved. Please contact the IT administrator.';
    exit;
}

foreach ((array) ($_SESSION['_pdf_downloads'] ?? []) as $storedToken => $grant) {
    if (!is_array($grant) || (int) ($grant['expires_at'] ?? 0) < time()) {
        unset($_SESSION['_pdf_downloads'][$storedToken]);
    }
}

$downloadToken = bin2hex(random_bytes(32));
$_SESSION['_pdf_downloads'][$downloadToken] = [
    'file' => $filename,
    'expires_at' => time() + 900,
];
$downloadUrl = 'download_pdf.php?' . http_build_query([
    'portal' => $returnContext,
    'token' => $downloadToken,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Ready</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background:
                linear-gradient(rgba(236, 243, 249, 0.40), rgba(230, 238, 246, 0.58)),
                url('assets/cloud1.jpg') center/cover fixed no-repeat;
            color: #111;
        }

        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: #fff;
            border: 1px solid #d5dde6;
            border-radius: 18px;
            box-shadow: 0 18px 48px rgba(28, 46, 67, 0.12);
            padding: 34px;
            text-align: center;
        }

        .eyebrow {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: #eef4fb;
            color: #2b537f;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 30px;
            color: #162739;
        }

        p {
            margin: 0 0 24px;
            line-height: 1.6;
            color: #506377;
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            text-decoration: none;
            font-weight: 700;
            border-radius: 12px;
            border: 1px solid #1f5fbf;
        }

        .btn-primary {
            background: linear-gradient(180deg, #1f5fbf 0%, #184d9c 100%);
            color: #fff;
            box-shadow: 0 10px 20px rgba(31, 95, 191, 0.18);
        }

        .btn-secondary {
            background: #fff;
            color: #1f5fbf;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="eyebrow">Submission Complete</div>
            <h1>PDF Ready to Download</h1>
            <p>Your request has been submitted successfully. You can download the PDF copy below for your records.</p>
            <div class="actions">
                <a class="btn btn-primary" href="<?= escape($downloadUrl) ?>" download>Download PDF</a>
                <a class="btn btn-secondary" href="<?= escape($formReturnUrl) ?>">Back to Form</a>
                <a class="btn btn-secondary" href="<?= escape($portalReturnUrl) ?>"><?= escape($portalReturnLabel) ?></a>
            </div>
        </div>
    </div>
</body>
</html>
