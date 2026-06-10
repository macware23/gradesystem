<?php
/**
 * GradeFlow - PDF Report Generator
 * Supports: type=final | term | attendance
 * Paper: folio 8.5×13 landscape (default) | letter | a4
 * Font size: configurable per user settings
 */
ob_start();   // catch any PHP notices/warnings before PDF output
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grade_engine.php';
require_once __DIR__ . '/../includes/fpdf.php';
require_login();

$classId = (int)($_GET['class_id'] ?? 0);
if (!owns_class($classId)) { http_response_code(403); die('Not authorized'); }

$type = $_GET['type'] ?? 'final';
$term = $_GET['term'] ?? '';
// Optional criteria filter: comma-separated criterion IDs
$criteriaFilter = isset($_GET['criteria_ids']) && $_GET['criteria_ids'] !== ''
    ? array_map('intval', explode(',', $_GET['criteria_ids']))
    : null;
// Optional activity filter: comma-separated activity IDs
$activityFilter = isset($_GET['activity_ids']) && $_GET['activity_ids'] !== ''
    ? array_map('intval', explode(',', $_GET['activity_ids']))
    : null;

$cls = db()->prepare('SELECT c.*, t.full_name AS teacher_name FROM classes c JOIN teachers t ON t.id=c.teacher_id WHERE c.id=?');
$cls->execute([$classId]); $class = $cls->fetch();
$cs    = class_settings($classId);
$terms = class_terms($classId);
$stQ   = db()->prepare('SELECT * FROM students WHERE class_id=? ORDER BY last_name,first_name');
$stQ->execute([$classId]); $students = $stQ->fetchAll();

$ss          = school_settings();
$schoolName  = $ss['school_name']     ?? '';
$schoolAddr  = $ss['school_address']  ?? '';
$subtitle    = $ss['system_subtitle'] ?? 'GradeFlow Grading System';
$logoPath    = $ss['logo_path']       ?? '';
$bodyFont    = in_array($ss['pdf_body_font']  ?? '', ['Helvetica','Times','Courier']) ? $ss['pdf_body_font']  : 'Helvetica';
$titleFont   = in_array($ss['pdf_title_font'] ?? '', ['Helvetica','Times','Courier']) ? $ss['pdf_title_font'] : 'Times';
$fontSize    = max(6, min(11, (float)($ss['pdf_font_size'] ?? 8)));

// Helper: convert UTF-8 name to ISO-8859-1 for FPDF
// Preserves Ñ/ñ (0xD1/0xF1 in ISO-8859-1), converts accented chars, drops unmappable
function pdfName(string $s): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;
}

function rgbArr(string $val, array $fb): array {
    $p = array_map('trim', explode(',', $val));
    return (count($p)===3 && is_numeric($p[0])) ? [(int)$p[0],(int)$p[1],(int)$p[2]] : $fb;
}
$hdrBg      = rgbArr($ss['pdf_header_bg']   ?? '', [29,36,51]);
$accRgb     = rgbArr($ss['pdf_accent_rgb']  ?? '', [201,123,31]);
$passRgb    = rgbArr($ss['pdf_pass_rgb']    ?? '', [47,125,84]);
$failRgb    = rgbArr($ss['pdf_fail_rgb']    ?? '', [178,59,59]);
$textRgb    = rgbArr($ss['pdf_text_rgb']    ?? '', [0,0,0]);
// Per-area text/fill overrides
$hdrTxtRgb    = rgbArr($ss['pdf_hdr_txt']      ?? '', [245,240,230]);
$subTitleRgb  = rgbArr($ss['pdf_subtitle_txt'] ?? '', $accRgb);
$titleTxtRgb  = rgbArr($ss['pdf_title_txt']    ?? '', [245,240,230]);
$subjTxtRgb   = rgbArr($ss['pdf_subj_txt']     ?? '', $textRgb);
$infoTxtRgb   = rgbArr($ss['pdf_info_txt']     ?? '', [60,60,80]);
$thBgRgb      = rgbArr($ss['pdf_th_bg']        ?? '', $hdrBg);
$thTxtRgb     = rgbArr($ss['pdf_th_txt']       ?? '', [245,240,230]);
$th2BgRgb     = rgbArr($ss['pdf_th2_bg']       ?? '', [45,65,45]);
$th2TxtRgb    = rgbArr($ss['pdf_th2_txt']      ?? '', [245,240,230]);
$rowOddRgb    = rgbArr($ss['pdf_row_odd']       ?? '', [255,255,255]);
$rowEvenRgb   = rgbArr($ss['pdf_row_even']      ?? '', [248,246,240]);
$borderRgb    = rgbArr($ss['pdf_border']        ?? '', [200,193,180]);
$equivRgb     = rgbArr($ss['pdf_equiv']         ?? '', [216,236,223]);
$equivTxtRgb  = rgbArr($ss['pdf_equiv_txt']     ?? '', [30,100,60]);
$wsRgb        = rgbArr($ss['pdf_ws']            ?? '', [240,217,181]);
$wsTxtRgb     = rgbArr($ss['pdf_ws_txt']        ?? '', [100,70,10]);
$gradeRgb     = rgbArr($ss['pdf_grade']         ?? '', [240,217,181]);
$gradeTxtRgb  = rgbArr($ss['pdf_grade_txt']     ?? '', [80,50,10]);
$incRgb       = rgbArr($ss['pdf_inc']           ?? '', [220,220,220]);
$incTxtRgb    = rgbArr($ss['pdf_inc_txt']       ?? '', [80,80,80]);
$passTxtRgb   = rgbArr($ss['pdf_pass_txt']      ?? '', [255,255,255]);
$failTxtRgb   = rgbArr($ss['pdf_fail_txt']      ?? '', [255,255,255]);
$termColRgb   = rgbArr($ss['pdf_term_col']      ?? '', [255,255,255]);
$termColTxtRgb= rgbArr($ss['pdf_term_col_txt']  ?? '', [0,0,0]);
$finalColRgb  = rgbArr($ss['pdf_final_col']     ?? '', [255,255,255]);
$finalColTxtRgb=rgbArr($ss['pdf_final_col_txt'] ?? '', [0,0,0]);
$footerTxtRgb = rgbArr($ss['pdf_footer_txt']    ?? '', [160,160,160]);
$sigTxtRgb    = rgbArr($ss['pdf_sig_txt']       ?? '', $textRgb);
$pageBgRgb    = rgbArr($ss['pdf_page_bg']       ?? '', [255,255,255]);

// Paper size dimensions [portrait_w_mm, portrait_h_mm]
$paperSizes = [
    'folio'  => [215.9, 330.2],   // 8.5×13 in
    'legal'  => [215.9, 355.6],   // 8.5×14 in
    'letter' => [215.9, 279.4],   // 8.5×11 in
    'a4'     => [210.0, 297.0],
];
// Each setting is 'paper-orient', e.g. 'folio-L', 'folio-P', 'letter-P'
$termSetting  = $ss['pdf_term_paper']  ?? 'folio-L';
$finalSetting = $ss['pdf_final_paper'] ?? 'folio-P';
$chosenSetting = ($type === 'final') ? $finalSetting : $termSetting;
$parts       = explode('-', strtolower($chosenSetting));
$paperDims   = $paperSizes[$parts[0]] ?? $paperSizes['folio'];
$orientation = isset($parts[1]) && strtoupper($parts[1]) === 'P' ? 'P' : 'L';

// ----------------------------------------------------------------
// Custom FPDF class
// ----------------------------------------------------------------
class GradeReport extends FPDF {
    public string $rptTitle   = '';
    public int    $passedCount = 0;
    public int    $failedCount = 0;
    public string $schoolName = '';
    public string $schoolAddr = '';
    public string $subtitle   = 'GradeFlow Grading System';
    public string $logoPath   = '';
    public string $bodyFont   = 'Helvetica';
    public string $titleFont  = 'Times';
    public float  $bodySize   = 8.0;
    public array  $hdrBg      = [29,36,51];
    public array  $accRgb     = [201,123,31];
    public array  $textRgb    = [0,0,0];
    // Per-area colors
    public array  $hdrTxtRgb    = [245,240,230];
    public array  $subTitleRgb  = [201,123,31];
    public array  $titleTxtRgb  = [245,240,230];
    public array  $subjTxtRgb   = [0,0,0];
    public array  $infoTxtRgb   = [60,60,80];
    public array  $thBgRgb      = [29,36,51];
    public array  $thTxtRgb     = [245,240,230];
    public array  $th2BgRgb     = [45,65,45];
    public array  $th2TxtRgb    = [245,240,230];
    public array  $rowOddRgb    = [255,255,255];
    public array  $rowEvenRgb   = [248,246,240];
    public array  $borderRgb    = [200,193,180];
    public array  $equivRgb     = [216,236,223];
    public array  $equivTxtRgb  = [30,100,60];
    public array  $wsRgb        = [240,217,181];
    public array  $wsTxtRgb     = [100,70,10];
    public array  $gradeRgb    = [240,217,181];
    public array  $gradeTxtRgb = [80,50,10];
    public array  $incRgb      = [220,220,220];
    public array  $incTxtRgb   = [80,80,80];
    public array  $termColRgb    = [255,255,255];
    public array  $termColTxtRgb = [0,0,0];
    public array  $finalColRgb   = [255,255,255];
    public array  $finalColTxtRgb= [0,0,0];
    public array  $failTxtRgb   = [255,255,255];
    public array  $footerTxtRgb = [160,160,160];
    public array  $sigTxtRgb    = [0,0,0];
    public array  $pageBgRgb    = [255,255,255];
    public float  $hdrHeight  = 28.0;
    public string $teacherName  = '';
    public string $semesterLine = '';
    public string $gradeLabel   = '';
    public string $courseLine   = '';
    public string $sectionLine  = '';
    public string $schedule     = '';
    // Per-line header font settings
    public string $hdrSemFont  = 'Helvetica'; public float $hdrSemSize  = 9.0;  public string $hdrSemStyle  = '';
    public string $hdrLblFont  = 'Times';     public float $hdrLblSize  = 11.0; public string $hdrLblStyle  = 'B';
    public string $hdrCrsFont  = 'Helvetica'; public float $hdrCrsSize  = 9.5;  public string $hdrCrsStyle  = '';
    public string $hdrSecFont  = 'Helvetica'; public float $hdrSecSize  = 9.5;  public string $hdrSecStyle  = 'B';
    public string $hdrSchFont  = 'Helvetica'; public float $hdrSchSize  = 9.0;  public string $hdrSchStyle  = 'B';
    private bool  $logoLoaded  = false;

    function Header() {
        $pw = $this->GetPageWidth();

        // White page background
        $this->SetFillColor(...$this->pageBgRgb);
        $this->Rect(0, 0, $pw, $this->GetPageHeight(), 'F');

        $y = 4.0;

        // ── Line 1: Logo (centered) ──────────────────────────────────
        if ($this->logoPath && file_exists($this->logoPath)) {
            try {
                $logoH = 24.0;
                $ext   = strtolower(pathinfo($this->logoPath, PATHINFO_EXTENSION));
                $logoW = $logoH; // default to square
                if (in_array($ext, ['png','jpg','jpeg','gif'])) {
                    $info = @getimagesize($this->logoPath);
                    if ($info && $info[0] > 0 && $info[1] > 0) {
                        $logoW = ($info[0] / $info[1]) * $logoH;
                    }
                }
                $imgX = max(0, ($pw - $logoW) / 2);
                $this->Image($this->logoPath, $imgX, $y, $logoW, $logoH);
                $y += $logoH + 2.0;
            } catch (\Throwable $e) {}
        }

        $y += 2.0;

        // ── Line 2: Semester and School Year (centered) ──────────────
        if ($this->semesterLine) {
            $this->SetXY(0, $y);
            $this->SetFont($this->hdrSemFont, $this->hdrSemStyle, $this->hdrSemSize);
            $this->SetTextColor(0, 0, 0);
            $lh = $this->hdrSemSize * 0.56;
            $this->Cell($pw, $lh, $this->semesterLine, 0, 1, 'C');
            $y += $lh + 0.5;
        }

        // ── Line 3: Grade Label (centered) ───────────────────────────
        $this->SetXY(0, $y);
        $this->SetFont($this->hdrLblFont, $this->hdrLblStyle, $this->hdrLblSize);
        $this->SetTextColor(0, 0, 0);
        $lh = $this->hdrLblSize * 0.6;
        $this->Cell($pw, $lh, $this->gradeLabel ?: strtoupper($this->rptTitle), 0, 1, 'C');
        $y += $lh + 0.5;

        // ── Line 4a: Course Name (centered) ──────────────────────────
        if ($this->courseLine) {
            $this->SetXY(0, $y);
            $this->SetFont($this->hdrCrsFont, $this->hdrCrsStyle, $this->hdrCrsSize);
            $this->SetTextColor(0, 0, 0);
            $lh = $this->hdrCrsSize * 0.56;
            $this->Cell($pw, $lh, $this->courseLine, 0, 1, 'C');
            $y += $lh + 0.5;
        }

        // ── Line 4b: Section (centered) ──────────────────────────────
        if ($this->sectionLine) {
            $this->SetXY(0, $y);
            $this->SetFont($this->hdrSecFont, $this->hdrSecStyle, $this->hdrSecSize);
            $this->SetTextColor(0, 0, 0);
            $lh = $this->hdrSecSize * 0.56;
            $this->Cell($pw, $lh, $this->sectionLine, 0, 1, 'C');
            $y += $lh + 0.5;
        }

        // ── Line 5: Schedule of Class (centered) ─────────────────────
        if ($this->schedule) {
            $this->SetXY(0, $y);
            $this->SetFont($this->hdrSchFont, $this->hdrSchStyle, $this->hdrSchSize);
            $this->SetTextColor(0, 0, 0);
            $lh = $this->hdrSchSize * 0.56;
            $this->Cell($pw, $lh, $this->schedule, 0, 1, 'C');
            $y += $lh + 0.5;
        }

        $y += 1.5;

        $this->hdrHeight = $y;
        $this->SetY($y);
        $this->SetTextColor(...$this->textRgb);
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetFont($this->bodyFont, 'I', 7);
        $this->SetTextColor(...$this->footerTxtRgb);
        $this->Cell(0, 5, $this->subtitle . '  -  Generated ' . date('F j, Y  g:i A') .
            '  -  Page ' . $this->PageNo() . '/{nb}', 0, 1, 'C');
        $this->SetFont($this->bodyFont, '', 6.5);
        $this->Cell(0, 5, 'Copyright ' . chr(169) . ' 2026 Arnel Maghinay. All rights reserved.', 0, 0, 'C');
    }

    function ClassInfo(array $class, array $cs): void {
        if ($this->GetY() < $this->hdrHeight + 1) $this->SetY($this->hdrHeight + 1);
        $this->SetFont($this->bodyFont, '', 8.5);
        $this->SetTextColor(...$this->infoTxtRgb);
        $info = [];
        $info[] = 'Instructor: ' . pdfName($class['teacher_name'] ?? '');
        if (!empty($class['subject_code'])) $info[] = 'Code: ' . $class['subject_code'];
        $info[] = 'Students Passed: ' . $this->passedCount;
        $info[] = 'Students Failed: ' . $this->failedCount;
        $this->Cell(0, 5, implode('     ', $info), 0, 1, 'C');
        $this->Ln(1);
        $this->SetFillColor(...$this->accRgb);
        $this->Rect(0, $this->GetY(), $this->GetPageWidth(), 0.8, 'F');
        $this->Ln(3);
        $this->SetTextColor(...$this->textRgb);
    }

    function TH(array $cols): void {
        $this->SetFont($this->bodyFont, 'B', $this->bodySize);
        $this->SetFillColor(...$this->thBgRgb);
        $this->SetTextColor(...$this->thTxtRgb);
        $this->SetDrawColor(...$this->borderRgb);
        $this->SetLineWidth(0.15);
        foreach ($cols as $c) $this->Cell($c['w'], 7, $c['label'], 1, 0, $c['align']??'C', true);
        $this->Ln();
        $this->SetDrawColor(...$this->borderRgb);
        $this->SetLineWidth(0.1);
        $this->SetTextColor(...$this->textRgb);
    }

    function DataRow(array $cols, array $vals, bool $even, int $hlCol=-1, string $pass='',
                     array $termCols=[], float $passing=75): void {
        $base    = $even ? $this->rowEvenRgb : $this->rowOddRgb;
        $white   = [255, 255, 255];
        $rh = 6.5;
        $this->SetFont($this->bodyFont, '', $this->bodySize);
        $this->SetDrawColor(...$this->borderRgb);
        foreach ($cols as $i => $c) {
            $val = $vals[$i] ?? '';

            if ($i === $hlCol) {
                // FINAL GRADE column — configurable fill for passing, red for failing
                if ($pass === 'F') {
                    $this->SetFillColor(...$this->failRgb);
                    $this->SetTextColor(...$this->failTxtRgb);
                } else {
                    $this->SetFillColor(...$this->finalColRgb);
                    $this->SetTextColor(...$this->finalColTxtRgb);
                }
                $this->SetFont($this->bodyFont,'B',$this->bodySize);
                $this->Cell($c['w'], $rh, $val, 1, 0, 'C', true);
                $this->SetFont($this->bodyFont,'', $this->bodySize);
                $this->SetFillColor(...$base);
                $this->SetTextColor(...$this->textRgb);

            } elseif (in_array($i, $termCols) && is_numeric($val)) {
                // TERM GRADE columns — configurable fill for passing, red for failing
                if ((float)$val < $passing) {
                    $this->SetFillColor(...$this->failRgb);
                    $this->SetTextColor(...$this->failTxtRgb);
                } else {
                    $this->SetFillColor(...$this->termColRgb);
                    $this->SetTextColor(...$this->termColTxtRgb);
                }
                $this->SetFont($this->bodyFont,'B',$this->bodySize);
                $this->Cell($c['w'], $rh, $val, 1, 0, 'C', true);
                $this->SetFont($this->bodyFont,'', $this->bodySize);
                $this->SetFillColor(...$base);
                $this->SetTextColor(...$this->textRgb);

            } elseif (!empty($c['bold'])) {
                $this->SetFillColor(...$base);
                $this->SetTextColor(...$this->textRgb);
                $this->SetFont($this->bodyFont,'B',$this->bodySize);
                $this->Cell($c['w'], $rh, $val, 1, 0, $c['align']??'C', true);
                $this->SetFont($this->bodyFont,'', $this->bodySize);
            } else {
                $this->SetFillColor(...$base);
                $this->SetTextColor(...$this->textRgb);
                $this->Cell($c['w'], $rh, $val, 1, 0, $c['align']??'C', true);
            }
        }
        $this->Ln();
    }

    /** Signature block */
    function SignatureBlock(): void {
        $this->Ln(10);
        $lm = $this->lMargin;
        $this->SetFont($this->bodyFont, 'B', $this->bodySize + 0.5);
        $this->SetTextColor(...$this->sigTxtRgb);
        $this->SetXY($lm, $this->GetY());
        $this->Cell(0, 6, 'Submitted by: ' . strtoupper($this->teacherName), 0, 1, 'L');
        $this->Cell(0, 6, 'Submitted on: ' . date('F j, Y'), 0, 1, 'L');
        $this->SetTextColor(...$this->textRgb);
        $this->SetDrawColor(...$this->borderRgb);
    }
}

// ---- Instantiate PDF ----
$pdf = new GradeReport($orientation, 'mm', $paperDims);
$pdf->hdrBg        = $hdrBg;
$pdf->accRgb       = $accRgb;
$pdf->passRgb      = $passRgb;
$pdf->failRgb      = $failRgb;
$pdf->textRgb      = $textRgb;
$pdf->hdrTxtRgb    = $hdrTxtRgb;
$pdf->subTitleRgb  = $subTitleRgb;
$pdf->titleTxtRgb  = $titleTxtRgb;
$pdf->subjTxtRgb   = $subjTxtRgb;
$pdf->infoTxtRgb   = $infoTxtRgb;
$pdf->thBgRgb      = $thBgRgb;
$pdf->thTxtRgb     = $thTxtRgb;
$pdf->th2BgRgb     = $th2BgRgb;
$pdf->th2TxtRgb    = $th2TxtRgb;
$pdf->rowOddRgb    = $rowOddRgb;
$pdf->rowEvenRgb   = $rowEvenRgb;
$pdf->borderRgb    = $borderRgb;
$pdf->equivRgb     = $equivRgb;
$pdf->equivTxtRgb  = $equivTxtRgb;
$pdf->wsRgb        = $wsRgb;
$pdf->wsTxtRgb     = $wsTxtRgb;
$pdf->passTxtRgb   = $passTxtRgb;
$pdf->failTxtRgb   = $failTxtRgb;
$pdf->termColRgb    = $termColRgb;
$pdf->termColTxtRgb = $termColTxtRgb;
$pdf->finalColRgb   = $finalColRgb;
$pdf->finalColTxtRgb= $finalColTxtRgb;
$pdf->gradeRgb     = $gradeRgb;
$pdf->gradeTxtRgb  = $gradeTxtRgb;
$pdf->incRgb       = $incRgb;
$pdf->incTxtRgb    = $incTxtRgb;
$pdf->footerTxtRgb = $footerTxtRgb;
$pdf->sigTxtRgb    = $sigTxtRgb;
$pdf->pageBgRgb    = $pageBgRgb;
$pdf->schoolName = $schoolName;
$pdf->schoolAddr = $schoolAddr;
$pdf->subtitle   = $subtitle;
$pdf->bodyFont   = $bodyFont;
$pdf->titleFont  = $titleFont;
$pdf->bodySize   = $fontSize;
$pdf->teacherName = pdfName($class['teacher_name'] ?? '');

// Use report logo for PDFs; fall back to system/nav logo if not set
$reportLogoPath = trim($ss['report_logo_path'] ?? '');
$activeLogo = '';
if ($reportLogoPath) {
    $rl = __DIR__ . '/../' . $reportLogoPath;
    if (file_exists($rl)) $activeLogo = $rl;
}
if (!$activeLogo && $logoPath) {
    $sl = __DIR__ . '/../' . $logoPath;
    if (file_exists($sl)) $activeLogo = $sl;
}
$pdf->logoPath = $activeLogo;

// Use Manila Philippines timezone for all dates
date_default_timezone_set('Asia/Manila');

// Build centered header lines from class data
$semLine = '';
if (!empty($class['semester']))   $semLine  = $class['semester'];
if (!empty($class['school_year'])) $semLine .= ($semLine ? ', A.Y. ' : 'A.Y. ') . $class['school_year'];

$pdf->semesterLine = $semLine;
$pdf->courseLine   = pdfName(trim($class['subject_name'] ?? ''));
$pdf->sectionLine  = pdfName(trim($class['section'] ?? ''));
$pdf->schedule     = pdfName(trim($class['schedule'] ?? ''));

// Per-line header font settings (family / size / style)
$validFonts = ['Helvetica','Times','Courier'];
$validStyle = fn(string $s): string => preg_replace('/[^BIUSD]/','', strtoupper($s));
$hdrSemFont  = in_array($ss['pdf_hdr_sem_font'] ?? '', $validFonts) ? $ss['pdf_hdr_sem_font']  : 'Helvetica';
$hdrSemSize  = max(6, min(24, (float)($ss['pdf_hdr_sem_size']  ?? 9)));
$hdrSemStyle = $validStyle($ss['pdf_hdr_sem_style'] ?? '');
$hdrLblFont  = in_array($ss['pdf_hdr_lbl_font'] ?? '', $validFonts) ? $ss['pdf_hdr_lbl_font']  : 'Times';
$hdrLblSize  = max(6, min(24, (float)($ss['pdf_hdr_lbl_size']  ?? 11)));
$hdrLblStyle = $validStyle($ss['pdf_hdr_lbl_style'] ?? 'B');
$hdrCrsFont  = in_array($ss['pdf_hdr_crs_font'] ?? '', $validFonts) ? $ss['pdf_hdr_crs_font']  : 'Helvetica';
$hdrCrsSize  = max(6, min(24, (float)($ss['pdf_hdr_crs_size']  ?? 9.5)));
$hdrCrsStyle = $validStyle($ss['pdf_hdr_crs_style'] ?? '');
$hdrSecFont  = in_array($ss['pdf_hdr_sec_font'] ?? '', $validFonts) ? $ss['pdf_hdr_sec_font']  : 'Helvetica';
$hdrSecSize  = max(6, min(24, (float)($ss['pdf_hdr_sec_size']  ?? 9.5)));
$hdrSecStyle = $validStyle($ss['pdf_hdr_sec_style'] ?? 'B');
$hdrSchFont  = in_array($ss['pdf_hdr_sch_font'] ?? '', $validFonts) ? $ss['pdf_hdr_sch_font']  : 'Helvetica';
$hdrSchSize  = max(6, min(24, (float)($ss['pdf_hdr_sch_size']  ?? 9)));
$hdrSchStyle = $validStyle($ss['pdf_hdr_sch_style'] ?? 'B');

$pdf->hdrSemFont  = $hdrSemFont;  $pdf->hdrSemSize  = $hdrSemSize;  $pdf->hdrSemStyle  = $hdrSemStyle;
$pdf->hdrLblFont  = $hdrLblFont;  $pdf->hdrLblSize  = $hdrLblSize;  $pdf->hdrLblStyle  = $hdrLblStyle;
$pdf->hdrCrsFont  = $hdrCrsFont;  $pdf->hdrCrsSize  = $hdrCrsSize;  $pdf->hdrCrsStyle  = $hdrCrsStyle;
$pdf->hdrSecFont  = $hdrSecFont;  $pdf->hdrSecSize  = $hdrSecSize;  $pdf->hdrSecStyle  = $hdrSecStyle;
$pdf->hdrSchFont  = $hdrSchFont;  $pdf->hdrSchSize  = $hdrSchSize;  $pdf->hdrSchStyle  = $hdrSchStyle;

$pdf->rptTitle   = $type === 'final' ? 'Final Grade Report'
                 : ($type === 'term' ? $term . ' Grade Report'
                 : $term . ' Attendance Report');
$pdf->gradeLabel = $type === 'final' ? 'FINAL CLASS GRADE'
                 : ($type === 'term' ? strtoupper($term) . ' GRADE'
                 : strtoupper($term) . ' ATTENDANCE');

$pdf->hdrHeight  = 28.0;
$pdf->SetMargins(8, 8, 8);
$pdf->AliasNbPages();
// Disable FPDF's built-in auto page break so our manual check (which also
// draws ClassInfo + the table header rows) is the only page-break mechanism.
// Without this, FPDF fires AddPage()+Header() internally before our check,
// producing continuation pages that have the document title but no table headers.
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
// ClassInfo is called inside each type block AFTER pass/fail counts are set

// ================================================================
// FINAL GRADE REPORT
// ================================================================
if ($type === 'final') {
    // Pre-compute pass/fail counts for the info line
    $prePass = 0; $preFail = 0;
    foreach ($students as $s) {
        $g = compute_final_grade((int)$s['id'], $classId);
        if ($g['final'] !== null) {
            $p = passes($classId, $g['final']);
            if ($p === true) $prePass++;
            elseif ($p === false) $preFail++;
        }
    }
    $pdf->passedCount = $prePass;
    $pdf->failedCount = $preFail;
    $pdf->ClassInfo($class, $cs);
    // Dynamic term column width
    $tW = max(18, min(32, (int)(60 / max(1,count($terms)))));
    $cols = [
        ['label'=>'#',           'w'=>7,  'align'=>'C'],
        ['label'=>'Student No',  'w'=>20, 'align'=>'C'],
        ['label'=>'Student Name','w'=>60, 'align'=>'L'],
    ];
    foreach ($terms as $t) $cols[] = ['label'=>$t.' GRADE', 'w'=>$tW, 'align'=>'C', 'wrap'=>true];
    // Track which column indices are term grade columns (for per-cell fail highlighting)
    $termColIndices = range(3, 2 + count($terms));
    $finalCol = count($cols);
    $cols[] = ['label'=>'FINAL GRADE','w'=>22,'align'=>'C','bold'=>true];
    $cols[] = ['label'=>'Remarks',    'w'=>22,'align'=>'C'];

    // Centre the table horizontally on the page
    $tableW = array_sum(array_column($cols,'w'));
    $marginX = max(8, ($pdf->GetPageWidth() - $tableW) / 2);
    $pdf->SetLeftMargin($marginX);
    $pdf->SetRightMargin($marginX);
    $pdf->SetX($marginX);

    // Define row heights BEFORE the closure so $thH is captured correctly
    $rowH = 6.5;
    $thH  = 12;   // taller header to accommodate wrapped term labels

    // Helper: draw the wrapped MultiCell header row
    $drawFinalHeader = function() use ($pdf,$cols,$bodyFont,$fontSize,$thH,
        $thBgRgb,$thTxtRgb,$borderRgb,$textRgb,$marginX) {
        $pdf->SetX($marginX);
        $pdf->SetFont($bodyFont,'B',$fontSize);
        $pdf->SetFillColor(...$thBgRgb);
        $pdf->SetTextColor(...$thTxtRgb);
        $pdf->SetDrawColor(...$borderRgb); $pdf->SetLineWidth(0.15);
        foreach ($cols as $c) {
            $x=$pdf->GetX(); $y=$pdf->GetY();
            $pdf->MultiCell($c['w'],$thH,$c['label'],1,'C',true);
            $pdf->SetXY($x+$c['w'],$y);
        }
        $pdf->Ln($thH);
        $pdf->SetDrawColor(...$borderRgb); $pdf->SetLineWidth(0.1);
        $pdf->SetTextColor(...$textRgb);
        $pdf->SetX($marginX);
    };

    $drawFinalHeader();

    // Break when the next data row would overlap the footer (14 mm) + 5 mm buffer
    $bottomY = $pdf->GetPageHeight() - 19;
    $i=1; $even=false;
    foreach ($students as $s) {
        if ($pdf->GetY() + $rowH > $bottomY) {
            $pdf->AddPage();
            $pdf->SetLeftMargin($marginX); $pdf->SetRightMargin($marginX);
            $pdf->ClassInfo($class,$cs);
            $pdf->SetX($marginX);
            $drawFinalHeader();
            $even = false;
        }
        $g = compute_final_grade((int)$s['id'],$classId);
        $final=$g['final']; $pass=passes($classId,$final);
        $pdf->SetX($marginX);
        $vals=[$i++,$s['student_no'],pdfName(' '.$s['last_name'].', '.$s['first_name'])];
        foreach($terms as $t){
            $tg = $g['terms'][$t] ?? null;
            $vals[] = $tg !== null ? number_format($tg, 0) : number_format($cs['zero_equiv'], 0);
        }
        $vals[]=$final!==null?number_format($final,0):'-';
        $vals[]=$pass===true?'PASSED':($pass===false?'FAILED':'INC');
        $ps=$pass===true?'P':($pass===false?'F':'');
        $pdf->DataRow($cols,$vals,$even,$finalCol,$ps,$termColIndices,(float)$cs['passing']);
        $even=!$even;
    }
    $pdf->SignatureBlock();

// ================================================================
// TERM REPORT — FULL DETAIL (all activities with raw + equivalent)
// ================================================================
} elseif ($type === 'term') {
    if (!in_array($term,$terms)) die('Invalid term');

    // Pre-compute pass/fail counts based on term grade
    $prePassT = 0; $preFailT = 0;
    foreach ($students as $s) {
        $tc = compute_term((int)$s['id'], $classId, $term, $cs);
        if ($tc['grade'] !== null) {
            if ($tc['grade'] >= $cs['passing']) $prePassT++;
            else $preFailT++;
        }
    }
    $pdf->passedCount = $prePassT;
    $pdf->failedCount = $preFailT;
    $pdf->ClassInfo($class, $cs);

    // Load criteria + their activities (apply user's selection if provided)
    $crQ = db()->prepare('SELECT * FROM criteria WHERE class_id=? AND term=? ORDER BY sort_order,id');
    $crQ->execute([$classId,$term]); $allCriteria=$crQ->fetchAll();
    // Filter to only selected criteria if user chose a subset
    $criteria = $criteriaFilter !== null
        ? array_values(array_filter($allCriteria, fn($c)=>in_array((int)$c['id'],$criteriaFilter)))
        : $allCriteria;
    $actQ = db()->prepare('SELECT * FROM activities WHERE criterion_id=? ORDER BY sort_order,id');
    foreach ($criteria as &$c) {
        $actQ->execute([$c['id']]);
        $allActs = $actQ->fetchAll();
        // Apply activity filter if provided
        $c['acts'] = $activityFilter !== null
            ? array_values(array_filter($allActs, fn($a) => in_array((int)$a['id'], $activityFilter)))
            : $allActs;
    }
    unset($c);
    // Drop criteria that end up with zero activities after filtering
    if ($activityFilter !== null) {
        $criteria = array_values(array_filter($criteria, fn($c) => count($c['acts']) > 0));
    }

    // Load all raw scores
    $scQ = db()->prepare('SELECT s.student_id,s.activity_id,s.raw_score FROM scores s JOIN students st ON st.id=s.student_id WHERE st.class_id=?');
    $scQ->execute([$classId]);
    $scoreMap=[];
    foreach($scQ->fetchAll() as $r) $scoreMap[$r['student_id']][$r['activity_id']]=$r['raw_score'];

    // ---- Auto-fit column widths to the selected paper width ----
    // Raw scores and equivalents are whole numbers — they need less space than decimals.
    // AVG and WS keep 2 decimals so they need a bit more room.
    $pageW = $pdf->GetPageWidth() - 16;   // 8mm margin each side

    $totalActs = array_sum(array_map(fn($c) => count($c['acts']), $criteria));
    $critCount = count($criteria);

    // Minimum widths (mm) — kept as small as legible to maximise name column
    $numW   = 5;
    $gradeW = 14;   // just "GRADE" — one word, can be narrow
    $avgW   = 9;    // "XX.XX" — tight but readable
    $wsW    = 8;    // "XX.XX"
    $rawW   = 6;    // whole numbers only
    $eqW    = 5;    // whole numbers only
    $nameW  = 35;   // generous minimum so full names show

    // Total at minimums
    $minTotal = $numW + $nameW + $gradeW
              + $critCount * ($avgW + $wsW)
              + $totalActs * ($rawW + $eqW);

    if ($minTotal > $pageW) {
        // Even minimums overflow — reduce font size and scale columns down
        $ratio = $pageW / $minTotal;
        $fontSize = max(5.5, round($fontSize * $ratio * 0.95, 1));
        $rawW  = max(5,  round($rawW  * $ratio));
        $eqW   = max(4,  round($eqW   * $ratio));
        $avgW  = max(8,  round($avgW  * $ratio));
        $wsW   = max(7,  round($wsW   * $ratio));
        $nameW = max(28, round($nameW * $ratio));  // never below 28mm
    }

    // Distribute remaining space: 85% to name, 15% to raw columns
    $usedW = $numW + $nameW + $gradeW
           + $critCount * ($avgW + $wsW)
           + $totalActs * ($rawW + $eqW);
    $extra = $pageW - $usedW;
    if ($extra > 0) {
        $nameW += (int)round($extra * 0.85);
        $rawBonus = (int)round($extra * 0.15 / max(1, $totalActs));
        $rawW += $rawBonus;
    }

    // Final precision: absorb any remaining mm into name column
    $finalTotal = $numW + $nameW + $gradeW
                + $critCount * ($avgW + $wsW)
                + $totalActs * ($rawW + $eqW);
    $nameW = max(28, $nameW + ($pageW - $finalTotal));

    // Centre the entire term table horizontally
    $tableW = $numW + $nameW + $gradeW
            + $critCount * ($avgW + $wsW)
            + $totalActs * ($rawW + $eqW);
    $marginX = max(4, ($pdf->GetPageWidth() - $tableW) / 2);
    $pdf->SetLeftMargin($marginX);
    $pdf->SetRightMargin($marginX);
    $pdf->SetX($marginX);

    $pdf->bodySize = $fontSize;
    $rowH = max(5.5, $fontSize * 0.82);

    // Single header-drawing function used for page 1 AND every continuation page
    $drawTermHeader = function() use ($pdf,$criteria,$bodyFont,$fontSize,
        $avgW,$wsW,$numW,$nameW,$gradeW,$rawW,$eqW,$rowH,$textRgb,
        $thBgRgb,$thTxtRgb,$th2BgRgb,$th2TxtRgb,$borderRgb,$marginX) {
        // Row 1: criterion group spans  (#  |  Student Name  |  Criteria…  |  GRADE)
        $pdf->SetX($marginX);
        $pdf->SetFont($bodyFont,'B',$fontSize);
        $pdf->SetFillColor(...$thBgRgb);
        $pdf->SetTextColor(...$thTxtRgb);
        $pdf->SetDrawColor(...$borderRgb); $pdf->SetLineWidth(0.15);
        $pdf->Cell($numW,$rowH,'#',1,0,'C',true);
        $pdf->Cell($nameW,$rowH,'Student Name',1,0,'L',true);
        foreach($criteria as $c){
            $span = count($c['acts'])*($rawW+$eqW) + $avgW + $wsW;
            $pdf->Cell($span,$rowH,$c['name'].' ('.number_format($c['weight'],0).'%)',1,0,'C',true);
        }
        $pdf->Cell($gradeW,$rowH,'GRADE',1,1,'C',true);
        // Row 2: activity labels lined under each criterion
        $pdf->SetX($marginX);
        $pdf->SetFillColor(...$th2BgRgb); $pdf->SetTextColor(...$th2TxtRgb);
        $pdf->Cell($numW,5,'',1,0,'C',true);
        $pdf->Cell($nameW,5,'',1,0,'C',true);
        foreach($criteria as $c){
            foreach($c['acts'] as $a){
                $pdf->Cell($rawW,5,$a['label'],1,0,'C',true);
                $pdf->Cell($eqW, 5,'Eq',1,0,'C',true);
            }
            $pdf->Cell($avgW,5,'AVG',1,0,'C',true);
            $pdf->Cell($wsW, 5,'WS',1,0,'C',true);
        }
        $pdf->Cell($gradeW,5,'',1,1,'C',true);
        // Row 3: perfect-score sub-labels
        $pdf->SetX($marginX);
        $pdf->SetFillColor(...$th2BgRgb); $pdf->SetTextColor(...$th2TxtRgb);
        $pdf->Cell($numW,4,'',1,0,'C',true);
        $pdf->Cell($nameW,4,'',1,0,'L',true);
        foreach($criteria as $c){
            foreach($c['acts'] as $a){
                $pdf->Cell($rawW,4,'/'.(int)$a['perfect_score'],1,0,'C',true);
                $pdf->Cell($eqW, 4,'',1,0,'C',true);
            }
            $pdf->Cell($avgW,4,'',1,0,'C',true);
            $pdf->Cell($wsW, 4,'',1,0,'C',true);
        }
        $pdf->Cell($gradeW,4,'',1,1,'C',true);
        $pdf->SetDrawColor(...$borderRgb); $pdf->SetLineWidth(0.1);
        $pdf->SetTextColor(...$textRgb);
        $pdf->SetX($marginX);
    };

    $drawTermHeader();

    // ---- DATA ROWS ----
    // Break when the next row would overlap the footer (14 mm) + 5 mm buffer
    $bottomY = $pdf->GetPageHeight() - 19;
    $i=1; $even=false;
    foreach($students as $s){
        if($pdf->GetY() + $rowH > $bottomY){
            $pdf->AddPage();
            $pdf->SetLeftMargin($marginX); $pdf->SetRightMargin($marginX);
            $pdf->ClassInfo($class,$cs);
            $drawTermHeader();
            $even = false;
        }
        $tc = compute_term((int)$s['id'],$classId,$term,$cs);
        $base = $even ? $rowEvenRgb : $rowOddRgb;
        $pdf->SetX($marginX);
        $pdf->SetFillColor(...$base);
        $pdf->SetFont($bodyFont,'',$fontSize);
        $pdf->SetTextColor(...$textRgb);
        $pdf->Cell($numW,$rowH,$i++,1,0,'C',true);
        $pdf->Cell($nameW,$rowH,pdfName(' '.$s['last_name'].', '.$s['first_name']),1,0,'L',true);

        foreach($criteria as $c){
            $cc=$tc['criteria'][$c['name']]??['average'=>null,'ws'=>null];
            foreach($c['acts'] as $a){
                $raw=$scoreMap[$s['id']][$a['id']]??null;
                // Raw scores: whole numbers only (saves column space)
                $rawDisp = $raw!==null ? number_format((float)$raw, 0) : '-';
                $pdf->SetFillColor(...$base);
                $pdf->SetTextColor(...$pdf->textRgb);
                $pdf->Cell($rawW,$rowH,$rawDisp,1,0,'C',true);
                // Equivalent cell — green tint
                if($raw!==null){
                    $eq=mags_equivalent((float)$a['perfect_score'],(float)$raw,$cs['cutoff'],$cs['zero_equiv']);
                    $pdf->SetFillColor(...$pdf->equivRgb);
                    $pdf->SetFont($bodyFont,'B',$fontSize);
                    $pdf->SetTextColor(...$pdf->equivTxtRgb);
                    $pdf->Cell($eqW,$rowH,number_format($eq,0),1,0,'C',true);
                    $pdf->SetFont($bodyFont,'',$fontSize);
                    $pdf->SetFillColor(...$base);
                } else {
                    $pdf->SetTextColor(...$pdf->textRgb);
                    $pdf->Cell($eqW,$rowH,'-',1,0,'C',true);
                }
            }
            // AVG
            $pdf->SetTextColor(...$pdf->textRgb);
            $pdf->Cell($avgW,$rowH,$cc['average']!==null?number_format($cc['average'],2):'-',1,0,'C',true);
            // WS — configured fill + text
            $pdf->SetFillColor(...$pdf->wsRgb);
            $pdf->SetFont($bodyFont,'B',$fontSize);
            $pdf->SetTextColor(...$pdf->wsTxtRgb);
            $pdf->Cell($wsW,$rowH,$cc['ws']!==null?number_format($cc['ws'],2):'-',1,0,'C',true);
            $pdf->SetFont($bodyFont,'',$fontSize);
            $pdf->SetFillColor(...$base);
            $pdf->SetTextColor(...$pdf->textRgb);
        }

        // Term grade cell — grade fill for pass, fail fill for below passing, inc for no grade
        $g=$tc['grade'];
        $ps=($g!==null&&$g>=$cs['passing'])?'P':($g!==null?'F':'');
        if($ps==='P')      { $fill=$pdf->gradeRgb;  $txtClr=$pdf->gradeTxtRgb; }
        elseif($ps==='F')  { $fill=$pdf->failRgb;   $txtClr=$pdf->failTxtRgb;  }
        else               { $fill=$pdf->incRgb;    $txtClr=$pdf->incTxtRgb;   }
        $pdf->SetFillColor(...$fill);
        $pdf->SetTextColor(...$txtClr);
        $pdf->SetFont($bodyFont,'B',$fontSize+0.5);
        $pdf->Cell($gradeW,$rowH,$g!==null?number_format($g,0):'-',1,1,'C',true);
        $pdf->SetFont($bodyFont,'',$fontSize);
        $pdf->SetTextColor(...$textRgb);
        $pdf->SetFillColor(...$base);
        $pdf->SetX($marginX);
        $even=!$even;
    }
    $pdf->SignatureBlock();

// ================================================================
// ATTENDANCE REPORT
// ================================================================
} elseif ($type === 'attendance') {
    if (!in_array($term,$terms)) die('Invalid term');
    $pdf->ClassInfo($class, $cs);
    $sessQ=db()->prepare('SELECT * FROM attendance_sessions WHERE class_id=? AND term=? ORDER BY sort_order,session_date,id');
    $sessQ->execute([$classId,$term]); $sessions=$sessQ->fetchAll(); $total=count($sessions);
    $recMap=[];
    if($sessions){ $sids=implode(',',array_column($sessions,'id'));
        foreach(db()->query("SELECT session_id,student_id,status FROM attendance_records WHERE session_id IN ($sids)")->fetchAll() as $r)
            $recMap[$r['session_id']][$r['student_id']]=$r['status']; }
    $sw=max(8,min(14,(int)(120/max(1,$total))));
    $cols=[['label'=>'#','w'=>8,'align'=>'C'],['label'=>'Student Name','w'=>55,'align'=>'L']];
    foreach(array_slice($sessions,0,22) as $sess2) $cols[]=['label'=>$sess2['label'].($sess2['session_date']?' '.substr($sess2['session_date'],5):''),'w'=>$sw,'align'=>'C'];
    $cols[]=['label'=>'P','w'=>11,'align'=>'C'];$cols[]=['label'=>'A','w'=>11,'align'=>'C'];
    $cols[]=['label'=>'L','w'=>11,'align'=>'C'];$cols[]=['label'=>'Rate','w'=>15,'align'=>'C'];
    $bottomY = $pdf->GetPageHeight() - 19;
    $pdf->TH($cols); $i=1; $even=false;
    foreach($students as $s){
        if($pdf->GetY() + 6.5 > $bottomY){$pdf->AddPage();$pdf->ClassInfo($class,$cs);$pdf->TH($cols);}
        $vals=[$i++,pdfName(' '.$s['last_name'].', '.$s['first_name'])]; $P=$A=$L=0;
        foreach(array_slice($sessions,0,22) as $sess2){ $st=$recMap[$sess2['id']][$s['id']]??'P';$vals[]=$st; if($st==='P')$P++;elseif($st==='A')$A++;else$L++; }
        $rate=$total>0?number_format(($P+$L*0.5)/$total*100,0).'%':'--';
        $vals[]=$P;$vals[]=$A;$vals[]=$L;$vals[]=$rate;
        $pdf->SetFont($bodyFont,'',$fontSize); $base=$even?$rowEvenRgb:$rowOddRgb;
        foreach($cols as $ci=>$c){
            $val=$vals[$ci]??''; $fill=$base; $tc2=$textRgb; $bold=false;
            if(in_array($val,['P','A','L'])){
                if($val==='P')     { $fill=$equivRgb; $tc2=$equivTxtRgb; }
                elseif($val==='A') { $fill=$failRgb;  $tc2=$passTxtRgb; }
                else               { $fill=$wsRgb;    $tc2=$wsTxtRgb; }
                $bold=true;
            }
            $pdf->SetFillColor(...$fill); $pdf->SetTextColor(...$tc2);
            if($bold)$pdf->SetFont($bodyFont,'B',$fontSize);
            $pdf->Cell($c['w'],6.5,$val,1,0,$c['align']??'C',true);
            if($bold)$pdf->SetFont($bodyFont,'',$fontSize); $pdf->SetTextColor(...$textRgb);
        }
        $pdf->Ln(); $even=!$even;
    }
    $pdf->SignatureBlock();
}

$fn=preg_replace('/[^A-Za-z0-9]+/','_',$class['subject_name']).'_'.$type.'_'.$term.'.pdf';
ob_end_clean();   // discard any PHP warnings that would corrupt the PDF stream
$pdf->Output('I',$fn);
