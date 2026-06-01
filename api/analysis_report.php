<?php
/**
 * GradeFlow - AI Analysis PDF Report
 */
ob_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fpdf.php';
require_login();

$classId = (int)($_GET['class_id'] ?? 0);
if (!owns_class($classId)) { http_response_code(403); die('Not authorized'); }

$raw = $_POST['payload'] ?? '';
if (!$raw) die('No data');
$j = json_decode($raw, true);
if (!$j || !isset($j['results'])) die('Invalid data');

// Settings
$ss        = school_settings();
$schoolName = $ss['school_name']     ?? 'GradeFlow';
$schoolAddr = $ss['school_address']  ?? '';
$subtitle   = $ss['system_subtitle'] ?? 'GradeFlow Grading System';
$logoPath   = $ss['logo_path']       ?? '';
$bodyFont   = in_array($ss['pdf_body_font']  ?? '', ['Helvetica','Times','Courier']) ? $ss['pdf_body_font']  : 'Helvetica';
$titleFont  = in_array($ss['pdf_title_font'] ?? '', ['Helvetica','Times','Courier']) ? $ss['pdf_title_font'] : 'Times';
$fontSize   = max(7, min(10, (float)($ss['pdf_font_size'] ?? 8.5)));

function rgbA(string $v, array $fb): array {
    $p = array_map('trim', explode(',', $v));
    return (count($p)===3 && is_numeric($p[0])) ? [(int)$p[0],(int)$p[1],(int)$p[2]] : $fb;
}

// Helper: UTF-8 → ISO-8859-1 for FPDF, preserves Ñ/ñ
function pdfName(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;
}
$hdrBg        = rgbA($ss['ai_hdr_bg']         ?? '', [29,36,51]);
$hdrSchoolTxt = rgbA($ss['ai_hdr_school_txt'] ?? '', [0,0,0]);
$hdrAddrTxt   = rgbA($ss['ai_hdr_addr_txt']   ?? '', [0,0,0]);
$hdrTitleTxt  = rgbA($ss['ai_hdr_title_txt']  ?? '', [0,0,0]);
$sectionBg    = rgbA($ss['ai_section_bg']     ?? '', [29,36,51]);
$sectionTxt   = rgbA($ss['ai_section_txt']    ?? '', [0,0,0]);
$statsBg      = rgbA($ss['ai_stats_bg']       ?? '', [245,243,237]);
$statsValTxt  = rgbA($ss['ai_stats_val_txt']  ?? '', [0,0,0]);
$statsLblTxt  = rgbA($ss['ai_stats_lbl_txt']  ?? '', [0,0,0]);
$bodyTxt      = rgbA($ss['ai_body_txt']        ?? '', [0,0,0]);
$infoTxt      = rgbA($ss['ai_info_txt']        ?? '', [0,0,0]);
$footerTxt    = rgbA($ss['ai_footer_txt']      ?? '', [120,120,120]);
$accRgb       = rgbA($ss['ai_accent_rgb']      ?? '', [201,123,31]);
$pageBg       = rgbA($ss['ai_page_bg']         ?? '', [255,255,255]);
$textRgb      = $bodyTxt;

// Class info
$cls = db()->prepare('SELECT c.*, t.full_name AS teacher_name FROM classes c JOIN teachers t ON t.id=c.teacher_id WHERE c.id=?');
$cls->execute([$classId]); $class = $cls->fetch();

// ─── PDF class ──────────────────────────────────────────────────────
class AnalysisPDF extends FPDF {
    public array  $hdrBg        = [29,36,51];
    public array  $hdrSchoolTxt = [0,0,0];
    public array  $hdrAddrTxt   = [0,0,0];
    public array  $hdrTitleTxt  = [0,0,0];
    public array  $sectionBg    = [29,36,51];
    public array  $sectionTxt   = [0,0,0];
    public array  $statsBg      = [245,243,237];
    public array  $statsValTxt  = [0,0,0];
    public array  $statsLblTxt  = [0,0,0];
    public array  $accRgb       = [201,123,31];
    public array  $textRgb      = [0,0,0];
    public array  $infoTxt      = [0,0,0];
    public array  $footerTxt    = [120,120,120];
    public array  $pageBg       = [255,255,255];
    public string $schoolName = '';
    public string $schoolAddr = '';
    public string $subtitle   = '';
    public string $bodyFont   = 'Helvetica';
    public string $titleFont  = 'Times';
    public float  $bodySize   = 8.5;
    public string $logoPath   = '';
    public string $rptTitle   = 'AI Analysis Report';

    function Header() {
        // Page background
        $this->SetFillColor(...$this->pageBg);
        $this->Rect(0, 0, $this->GetPageWidth(), $this->GetPageHeight(), 'F');
        // Header bar
        $this->SetFillColor(...$this->hdrBg);
        $this->Rect(0, 0, $this->GetPageWidth(), 26, 'F');
        $x = 5;
        if ($this->logoPath && file_exists($this->logoPath)) {
            try { $this->Image($this->logoPath, $x, 3, 0, 20); $x += 23; } catch(\Throwable $e) {}
        }
        $this->SetXY($x, 4);
        $this->SetFont($this->titleFont, 'B', 13);
        $this->SetTextColor(...$this->hdrSchoolTxt);
        $this->Cell(0, 7, $this->schoolName, 0, 1, 'L');
        $this->SetX($x);
        $this->SetFont($this->bodyFont, '', 7.5);
        $this->SetTextColor(...$this->hdrAddrTxt);
        $this->Cell(130, 5, $this->schoolAddr ?: $this->subtitle, 0, 0, 'L');
        $this->SetFont($this->bodyFont, 'B', 8);
        $this->SetTextColor(...$this->hdrTitleTxt);
        $this->Cell(0, 5, $this->rptTitle, 0, 1, 'R');
        $this->SetY(30);
        $this->SetTextColor(...$this->textRgb);
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetFont($this->bodyFont, 'I', 6.5);
        $this->SetTextColor(...$this->footerTxt);
        $this->Cell(0, 5, $this->subtitle . '  -  Generated ' . date('F j, Y  g:i A') .
            '  -  Page ' . $this->PageNo() . '/{nb}', 0, 1, 'C');
        $this->SetFont($this->bodyFont, '', 6.5);
        $this->Cell(0, 5, 'Copyright ' . chr(169) . ' 2026 Arnel Maghinay. All rights reserved.', 0, 0, 'C');
    }

    function sectionTitle(string $text): void {
        $this->SetFont($this->bodyFont, 'B', $this->bodySize + 0.5);
        $this->SetFillColor(...$this->sectionBg);
        $this->SetTextColor(...$this->sectionTxt);
        $this->Cell(0, 7, $text, 0, 1, 'L', true);
        $this->SetTextColor(...$this->textRgb);
        $this->Ln(2);
    }

    // Wrapped multi-line cell
    function wrappedCell(float $w, string $txt, bool $bullet=false): void {
        if ($bullet) $txt = chr(149) . ' ' . $txt;
        $this->MultiCell($w > 0 ? $w : $this->GetPageWidth() - $this->lMargin - $this->rMargin,
            $this->bodySize * 0.42, $txt, 0, 'L');
    }
}

// ─── Build PDF ──────────────────────────────────────────────────────
$scope = $j['scope_label'] ?? ($j['type'] === 'final' ? 'Final Grade' : $j['type']);

$pdf = new AnalysisPDF('P', 'mm', [215.9, 330.2]);  // folio portrait
$pdf->hdrBg        = $hdrBg;
$pdf->hdrSchoolTxt = $hdrSchoolTxt;
$pdf->hdrAddrTxt   = $hdrAddrTxt;
$pdf->hdrTitleTxt  = $hdrTitleTxt;
$pdf->sectionBg    = $sectionBg;
$pdf->sectionTxt   = $sectionTxt;
$pdf->statsBg      = $statsBg;
$pdf->statsValTxt  = $statsValTxt;
$pdf->statsLblTxt  = $statsLblTxt;
$pdf->accRgb       = $accRgb;
$pdf->textRgb      = $textRgb;
$pdf->infoTxt      = $infoTxt;
$pdf->footerTxt    = $footerTxt;
$pdf->pageBg       = $pageBg;
$pdf->schoolName = $schoolName;
$pdf->schoolAddr = $schoolAddr;
$pdf->subtitle   = $subtitle;
$pdf->bodyFont   = $bodyFont;
$pdf->titleFont  = $titleFont;
$pdf->bodySize   = $fontSize;
$pdf->rptTitle   = 'AI Intervention Report - ' . $scope;
$pdf->logoPath   = $logoPath ? __DIR__ . '/../' . $logoPath : '';
$pdf->SetMargins(12, 12, 12);
$pdf->AliasNbPages();
$pdf->AddPage();

// ── Class header ────────────────────────────────────────────────────
$pdf->SetFont($titleFont, 'B', 13);
$pdf->SetTextColor(...$textRgb);
$pdf->Cell(0, 8, ($class['subject_name'] ?? '').' - '.$scope.' Intervention Report', 0, 1);
$pdf->SetFont($bodyFont, '', 8.5);
$pdf->SetTextColor(...$infoTxt);
$info = [];
if (!empty($class['subject_code'])) $info[] = 'Code: '.$class['subject_code'];
if (!empty($class['school_year']))  $info[] = 'S.Y.: '.$class['school_year'];
$info[] = 'Instructor: '.($class['teacher_name'] ?? '');
if (!empty($class['section']))      $info[] = 'Section: '.$class['section'];
$pdf->Cell(0, 5, implode('  -  ', $info), 0, 1);
$pdf->Ln(2);
$pdf->SetFillColor(...$accRgb);
$pdf->Rect(12, $pdf->GetY(), $pdf->GetPageWidth()-24, 0.8, 'F');
$pdf->Ln(5);

// ── Summary stats box ───────────────────────────────────────────────
$s = $j['summary'];
$pdf->SetFont($bodyFont, 'B', $fontSize);
$pdf->SetTextColor(...$textRgb);
$pw = ($pdf->GetPageWidth()-24)/4;
$stats = [
    ['Class Avg', $s['avg_grade'] ?? '-'],
    ['Passing',   $s['passing'].($s['passing_rate']!==null ? ' ('.$s['passing_rate'].'%)' : '')],
    ['Failing',   $s['failing']],
    ['At Risk',   $s['at_risk']],
];
foreach ($stats as $st) {
    $pdf->SetFillColor(...$pdf->statsBg);
    $pdf->SetFont($bodyFont, 'B', $fontSize + 3);
    $pdf->SetTextColor(...$pdf->statsValTxt);
    $pdf->Cell($pw, 10, $st[1], 0, 0, 'C', true);
}
$pdf->Ln(10);
foreach ($stats as $st) {
    $pdf->SetFont($bodyFont, '', $fontSize - 1);
    $pdf->SetTextColor(...$pdf->statsLblTxt);
    $pdf->Cell($pw, 5, $st[0], 0, 0, 'C');
}
$pdf->Ln(8);
$pdf->SetTextColor(...$textRgb);
$pdf->Ln(3);

// ── Per-student action plans ─────────────────────────────────────────
$pdf->sectionTitle('INDIVIDUAL STUDENT INTERVENTION PLANS');

$riskColors  = ['high'=>[200,50,50], 'medium'=>[200,130,20], 'low'=>[40,120,80]];
$riskBgColors= ['high'=>[253,236,234],'medium'=>[255,248,230],'low'=>[237,247,241]];

foreach ($j['results'] as $r) {
    // Check page break
    if ($pdf->GetY() > 270) $pdf->AddPage();

    $rc = $riskColors[$r['risk_level']]   ?? [60,60,60];
    $rb = $riskBgColors[$r['risk_level']] ?? [245,245,245];

    // Student name bar
    $pdf->SetFillColor(...$rb);
    $pdf->SetFont($bodyFont, 'B', $fontSize + 0.5);
    $pdf->SetTextColor(...$rc);
    $barW = $pdf->GetPageWidth() - 24;
    $pdf->Cell($barW * 0.55, 7, pdfName(' '.$r['name']), 0, 0, 'L', true);
    $pdf->SetFont($bodyFont, '', $fontSize);
    $pdf->Cell($barW * 0.2,  7, 'Grade: '.($r['grade'] ?? '-'), 0, 0, 'C', true);
    $pdf->SetFont($bodyFont, 'B', $fontSize);
    $pdf->Cell($barW * 0.25, 7, strtoupper($r['risk_level']).' RISK ('.$r['risk_score'].')', 0, 1, 'C', true);

    $pdf->SetTextColor(...$textRgb);
    $pdf->SetFont($bodyFont, '', $fontSize);

    // Weak areas
    if (!empty($r['weak_areas'])) {
        $pdf->SetFont($bodyFont, 'B', $fontSize - 0.5);
        $pdf->SetTextColor(...$textRgb);
        $pdf->Cell(0, 5, '  Areas needing improvement:', 0, 1);
        $pdf->SetFont($bodyFont, '', $fontSize - 0.5);
        foreach (array_slice($r['weak_areas'], 0, 3) as $w) {
            $pctBar = min(100, $w['pct']);
            $pdf->SetTextColor(...$textRgb);
            $pdf->Cell(60, 4.5, '    '.$w['name'].' ('.$w['term'].')', 0, 0);
            $pdf->SetTextColor(...$textRgb);
            $pdf->Cell(18, 4.5, $w['pct'].'%', 0, 0, 'R');
            // Mini progress bar
            $bx = $pdf->GetX() + 2; $by = $pdf->GetY() + 1;
            $bw = 60;
            $pdf->SetFillColor(220, 220, 215);
            $pdf->Rect($bx, $by, $bw, 2.5, 'F');
            $fillClr = $w['pct'] >= 75 ? [47,125,84] : ($w['pct'] >= 60 ? [200,130,20] : [178,59,59]);
            $pdf->SetFillColor(...$fillClr);
            $pdf->Rect($bx, $by, max(1, $bw * $pctBar / 100), 2.5, 'F');
            $pdf->SetXY($pdf->GetX() + $bw + 4, $pdf->GetY());
            $pdf->Cell(0, 4.5, 'weight '.$w['weight'].'%  -  impact '.$w['impact'].' pts', 0, 1);
        }
    }

    // Action steps
    $pdf->SetFont($bodyFont, 'B', $fontSize - 0.5);
    $pdf->SetTextColor(...$textRgb);
    $pdf->Cell(0, 5.5, '  What this student should do:', 0, 1);
    $pdf->SetTextColor(...$textRgb);
    $pdf->SetFont($bodyFont, '', $fontSize);
    $actions = $r['actions'] ?? [];
    foreach ($actions as $ai => $act) {
        $pdf->SetFillColor(248, 246, 238);
        $pdf->SetX(12);
        $prefix = '  '.($ai+1).'. ';
        // Use MultiCell for wrapping
        $lineH = $fontSize * 0.42;
        $pdf->MultiCell($pdf->GetPageWidth()-24, $lineH + 0.5,
            $prefix . $act, 0, 'L');
    }
    $pdf->Ln(3);
}

// ── Class narrative ─────────────────────────────────────────────────
if (!empty($j['narrative'])) {
    if ($pdf->GetY() > 240) $pdf->AddPage();
    $pdf->Ln(4);
    $pdf->sectionTitle('OVERALL CLASS ANALYSIS & TEACHER RECOMMENDATIONS');
    $pdf->SetFont($bodyFont, '', $fontSize);
    $pdf->SetTextColor(...$textRgb);
    $lineH = $fontSize * 0.42;
    $pdf->MultiCell($pdf->GetPageWidth()-24, $lineH + 0.8, $j['narrative'], 0, 'L');
    $pdf->Ln(3);
}

// ── Signature ───────────────────────────────────────────────────────
if ($pdf->GetY() > 270) $pdf->AddPage();
$pdf->Ln(10);
$pdf->SetFont($bodyFont, 'B', $fontSize);
$pdf->SetTextColor(...$textRgb);
$pdf->Cell(0, 6, 'Submitted by: '.pdfName(strtoupper($class['teacher_name'] ?? '')), 0, 1);
$pdf->Cell(0, 6, 'Submitted on: '.date('F j, Y'), 0, 1);
$pdf->Ln(2);
$pdf->SetFont($bodyFont, 'I', $fontSize - 1.5);
$pdf->SetTextColor(...$footerTxt);
$pdf->Cell(0, 4, 'Offline AI analysis - for professional decision support only. Not a substitute for teacher judgment.', 0, 1, 'L');

$fn = preg_replace('/[^A-Za-z0-9]+/', '_', ($class['subject_name']??'class')).'_AI_Report_'.date('Y-m-d').'.pdf';
ob_end_clean();
$pdf->Output('I', $fn);
