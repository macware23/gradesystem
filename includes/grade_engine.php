<?php
/**
 * GradeFlow — Grade calculation engine
 * Transmutation model: raw → equivalent → criterion avg → weighted sum → term grade → final
 *
 * PERFORMANCE: all bulk_compute_class() work is done in PHP after a single
 * batch data-load. Zero per-student queries. student_computed() falls back
 * to the per-student path only when called in isolation (e.g. after saving
 * a single cell).
 */
require_once __DIR__ . '/../config/config.php';

// ────────────────────────────────────────────────────────────────────
// Core math (pure functions, no DB)
// ────────────────────────────────────────────────────────────────────

function mags_equivalent(float $perfect, float $raw, float $cutoff, float $zeroequiv): float {
    if ($perfect <= 0) return $zeroequiv;
    $zpointshigh = 100 - $cutoff;
    $zpointslow  = $cutoff;
    $zequivhigh  = 25.0;          // 100 - 75
    $zequivlow   = 75 - $zeroequiv;
    $mulhigh = $zequivhigh != 0 ? $zpointshigh / $zequivhigh : 1;
    $mullow  = $zequivlow  != 0 ? $zpointslow  / $zequivlow  : 1;
    $pscore  = ($raw / $perfect) * 100;
    if ($pscore >= $cutoff) {
        return round(75 + ($pscore - $cutoff) / $mulhigh, 2);
    } elseif ($pscore > 0) {
        return round($zeroequiv + ($pscore / $mullow), 2);
    }
    return $zeroequiv;
}

function activity_equivalent(?float $raw, float $perfect, array $cs): ?float {
    if ($raw === null) return null;
    if ($cs['transmute']) return mags_equivalent($perfect, $raw, $cs['cutoff'], $cs['zero_equiv']);
    return $perfect > 0 ? round(($raw / $perfect) * 100, 2) : null;
}

// ────────────────────────────────────────────────────────────────────
// Single-row DB helpers (used only when bulk path isn't available)
// ────────────────────────────────────────────────────────────────────

function class_settings(int $classId): array {
    static $cache = [];
    if (isset($cache[$classId])) return $cache[$classId];
    $stmt = db()->prepare(
        'SELECT cutoff, zero_equiv, use_transmutation, passing_grade FROM classes WHERE id=?');
    $stmt->execute([$classId]);
    $r = $stmt->fetch();
    return $cache[$classId] = [
        'cutoff'     => (float)($r['cutoff']            ?? 50),
        'zero_equiv' => (float)($r['zero_equiv']         ?? 65),
        'transmute'  => (int)  ($r['use_transmutation']  ?? 1),
        'passing'    => (float)($r['passing_grade']       ?? 75),
    ];
}

function class_terms(int $classId): array {
    static $cache = [];
    if (isset($cache[$classId])) return $cache[$classId];
    $stmt = db()->prepare('SELECT term_system FROM classes WHERE id=?');
    $stmt->execute([$classId]);
    $row = $stmt->fetch();
    return $cache[$classId] = $row
        ? array_values(array_filter(array_map('trim', explode(',', $row['term_system']))))
        : [];
}

function passes(int $classId, ?float $grade): ?bool {
    if ($grade === null) return null;
    return $grade >= class_settings($classId)['passing'];
}

// ────────────────────────────────────────────────────────────────────
// Bulk engine — ONE call loads everything for a whole class
// Returns: [studentId => ['terms','final','passes','detail']]
// ────────────────────────────────────────────────────────────────────

function bulk_compute_class(int $classId): array {
    $cs    = class_settings($classId);
    $terms = class_terms($classId);
    if (!$terms) return [];

    $pdo = db();

    // 1. All student IDs for this class
    $sids = $pdo->query(
        "SELECT id FROM students WHERE class_id={$classId} ORDER BY sort_order,last_name,first_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$sids) return [];

    // 2. All criteria (with term, weight, id) — one query
    $criteria = $pdo->query(
        "SELECT id, term, name, weight FROM criteria WHERE class_id={$classId} ORDER BY sort_order, id"
    )->fetchAll();

    // 3. All activities per criterion — one query
    $acts = $pdo->query(
        "SELECT a.id, a.criterion_id, a.perfect_score
         FROM activities a
         JOIN criteria c ON c.id=a.criterion_id
         WHERE c.class_id={$classId}
         ORDER BY a.sort_order, a.id"
    )->fetchAll();

    // Index activities by criterion_id
    $actsByCrit = [];
    foreach ($acts as $a) $actsByCrit[(int)$a['criterion_id']][] = $a;

    // 4. All scores for this class — one query
    $rows = $pdo->query(
        "SELECT s.student_id, s.activity_id, s.raw_score
         FROM scores s
         JOIN students stu ON stu.id = s.student_id
         WHERE stu.class_id = {$classId}"
    )->fetchAll();

    // Index scores[student_id][activity_id] = raw_score
    $scores = [];
    foreach ($rows as $r) $scores[(int)$r['student_id']][(int)$r['activity_id']] = $r['raw_score'];

    // 5. Term weights — one query
    $tw = [];
    foreach ($pdo->query(
        "SELECT term, weight FROM term_weights WHERE class_id={$classId}"
    )->fetchAll() as $r) $tw[$r['term']] = (float)$r['weight'];

    // 6. Compute everything in pure PHP — zero more queries
    $critByTerm = [];
    foreach ($criteria as $c) $critByTerm[$c['term']][] = $c;

    $result = [];
    foreach ($sids as $sid) {
        $sid = (int)$sid;
        $termGrades = [];
        $detail     = [];

        foreach ($terms as $term) {
            $termCriteria = $critByTerm[$term] ?? [];

            $sumWs = 0.0; $weightUsed = 0.0; $any = false;
            $termDetail = [];

            foreach ($termCriteria as $c) {
                $cid  = (int)$c['id'];
                $acts = $actsByCrit[$cid] ?? [];
                if (!$acts) { $termDetail[$c['name']] = ['average'=>null,'ws'=>null,'weight'=>(float)$c['weight']]; continue; }

                $equivs = [];
                foreach ($acts as $a) {
                    $aid = (int)$a['id'];
                    $raw = $scores[$sid][$aid] ?? null;
                    $equivs[] = $raw !== null
                        ? (activity_equivalent((float)$raw, (float)$a['perfect_score'], $cs) ?? (float)$cs['zero_equiv'])
                        : (float)$cs['zero_equiv'];
                }
                $avg    = array_sum($equivs) / count($equivs);
                $weight = (float)$c['weight'];
                $ws     = $avg * ($weight / 100);
                $termDetail[$c['name']] = ['average'=>round($avg,2), 'ws'=>round($ws,2), 'weight'=>$weight];
                $any = true; $sumWs += $ws; $weightUsed += $weight;
            }

            $grade = null;
            if ($any) {
                $grade = $sumWs;
                if ($weightUsed > 0 && $weightUsed < 100) $grade = $sumWs * (100 / $weightUsed);
                $grade = (int)round($grade);
            }
            $termGrades[$term] = $grade;
            $detail[$term]     = $termDetail;
        }

        // Final grade
        $hasAny = array_filter($termGrades, fn($g) => $g !== null);
        $final  = null;
        if ($hasAny) {
            $sum = 0.0; $wTotal = 0.0;
            foreach ($terms as $t) {
                $w     = $tw[$t] ?? (100 / count($terms));
                $grade = $termGrades[$t] !== null ? (float)$termGrades[$t] : (float)$cs['zero_equiv'];
                $sum += $grade * $w; $wTotal += $w;
            }
            $final = $wTotal > 0 ? (int)round($sum / $wTotal) : null;
        }

        $result[$sid] = [
            'terms'  => $termGrades,
            'final'  => $final,
            'passes' => ($final === null ? null : $final >= $cs['passing']),
            'detail' => $detail,
        ];
    }
    return $result;
}

// ────────────────────────────────────────────────────────────────────
// Per-student path (used after saving individual cells)
// Uses the same bulk loader but scoped to one student
// ────────────────────────────────────────────────────────────────────

function student_computed_bulk(int $sid, int $classId): array {
    // Re-use bulk engine scoped to one student for cache coherence
    $all = _student_compute_single($sid, $classId);
    return $all;
}

function _student_compute_single(int $sid, int $classId): array {
    $cs    = class_settings($classId);
    $terms = class_terms($classId);
    $pdo   = db();

    // All criteria + activities for this class — tiny result set
    $criteria = $pdo->query(
        "SELECT id, term, name, weight FROM criteria WHERE class_id={$classId} ORDER BY sort_order, id"
    )->fetchAll();

    $acts = $pdo->query(
        "SELECT a.id, a.criterion_id, a.perfect_score
         FROM activities a JOIN criteria c ON c.id=a.criterion_id
         WHERE c.class_id={$classId} ORDER BY a.sort_order, a.id"
    )->fetchAll();
    $actsByCrit = [];
    foreach ($acts as $a) $actsByCrit[(int)$a['criterion_id']][] = $a;

    // Scores for THIS student only
    $scoreRows = $pdo->query(
        "SELECT activity_id, raw_score FROM scores WHERE student_id={$sid}"
    )->fetchAll();
    $myScores = [];
    foreach ($scoreRows as $r) $myScores[(int)$r['activity_id']] = $r['raw_score'];

    $tw = [];
    foreach ($pdo->query(
        "SELECT term, weight FROM term_weights WHERE class_id={$classId}"
    )->fetchAll() as $r) $tw[$r['term']] = (float)$r['weight'];

    $critByTerm = [];
    foreach ($criteria as $c) $critByTerm[$c['term']][] = $c;

    $termGrades = []; $detail = [];
    foreach ($terms as $term) {
        $termCriteria = $critByTerm[$term] ?? [];
        $sumWs = 0.0; $weightUsed = 0.0; $any = false; $termDetail = [];

        foreach ($termCriteria as $c) {
            $cid  = (int)$c['id'];
            $cActs = $actsByCrit[$cid] ?? [];
            if (!$cActs) { $termDetail[$c['name']] = ['average'=>null,'ws'=>null,'weight'=>(float)$c['weight']]; continue; }

            $equivs = [];
            foreach ($cActs as $a) {
                $aid = (int)$a['id'];
                $raw = $myScores[$aid] ?? null;
                $equivs[] = $raw !== null
                    ? (activity_equivalent((float)$raw, (float)$a['perfect_score'], $cs) ?? (float)$cs['zero_equiv'])
                    : (float)$cs['zero_equiv'];
            }
            $avg    = array_sum($equivs) / count($equivs);
            $weight = (float)$c['weight'];
            $ws     = $avg * ($weight / 100);
            $termDetail[$c['name']] = ['average'=>round($avg,2),'ws'=>round($ws,2),'weight'=>$weight];
            $any = true; $sumWs += $ws; $weightUsed += $weight;
        }

        $grade = null;
        if ($any) {
            $grade = $sumWs;
            if ($weightUsed > 0 && $weightUsed < 100) $grade = $sumWs * (100 / $weightUsed);
            $grade = (int)round($grade);
        }
        $termGrades[$term] = $grade;
        $detail[$term] = $termDetail;
    }

    $hasAny = array_filter($termGrades, fn($g) => $g !== null);
    $final  = null;
    if ($hasAny) {
        $sum = 0.0; $wTotal = 0.0;
        foreach ($terms as $t) {
            $w     = $tw[$t] ?? (100 / count($terms));
            $grade = $termGrades[$t] !== null ? (float)$termGrades[$t] : (float)$cs['zero_equiv'];
            $sum += $grade * $w; $wTotal += $w;
        }
        $final = $wTotal > 0 ? (int)round($sum / $wTotal) : null;
    }

    return [
        'terms'  => $termGrades,
        'final'  => $final,
        'passes' => ($final === null ? null : $final >= $cs['passing']),
        'detail' => $detail,
    ];
}

// ────────────────────────────────────────────────────────────────────
// Legacy wrappers — keep old call-sites working unchanged
// ────────────────────────────────────────────────────────────────────

function compute_criterion(int $studentId, array $criterion, array $cs): array {
    // Only used by legacy callers; the bulk path bypasses this entirely
    $stmt = db()->prepare(
        'SELECT a.id, a.perfect_score, s.raw_score
         FROM activities a LEFT JOIN scores s ON s.activity_id=a.id AND s.student_id=?
         WHERE a.criterion_id=? ORDER BY a.sort_order, a.id');
    $stmt->execute([$studentId, $criterion['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) return ['average'=>null,'ws'=>null,'weight'=>(float)$criterion['weight']];

    $equivs = [];
    foreach ($rows as $r) {
        $equivs[] = $r['raw_score'] !== null
            ? (activity_equivalent((float)$r['raw_score'], (float)$r['perfect_score'], $cs) ?? (float)$cs['zero_equiv'])
            : (float)$cs['zero_equiv'];
    }
    $avg = array_sum($equivs) / count($equivs);
    $w   = (float)$criterion['weight'];
    return ['average'=>round($avg,2),'ws'=>round($avg*$w/100,2),'weight'=>$w];
}

function compute_term(int $studentId, int $classId, string $term, array $cs): array {
    $stmt = db()->prepare('SELECT * FROM criteria WHERE class_id=? AND term=? ORDER BY sort_order, id');
    $stmt->execute([$classId, $term]);
    $criteria = $stmt->fetchAll();
    $detail = []; $sumWs = 0.0; $weightUsed = 0.0; $any = false;
    foreach ($criteria as $c) {
        $cc = compute_criterion($studentId, $c, $cs);
        $detail[$c['name']] = $cc;
        if ($cc['ws'] !== null) { $any=true; $sumWs+=$cc['ws']; $weightUsed+=$cc['weight']; }
    }
    if (!$any) return ['grade'=>null,'criteria'=>$detail];
    $grade = $sumWs;
    if ($weightUsed>0 && $weightUsed<100) $grade = $sumWs*(100/$weightUsed);
    return ['grade'=>(int)round($grade),'criteria'=>$detail];
}

function compute_term_grade(int $studentId, int $classId, string $term): ?float {
    $cs = class_settings($classId);
    return compute_term($studentId, $classId, $term, $cs)['grade'];
}

function compute_final_grade(int $studentId, int $classId): array {
    $terms = class_terms($classId);
    if (!$terms) return ['final'=>null,'terms'=>[]];
    $cs = class_settings($classId);
    $tw = [];
    foreach (db()->query("SELECT term,weight FROM term_weights WHERE class_id={$classId}")->fetchAll() as $r)
        $tw[$r['term']] = (float)$r['weight'];
    $termGrades = [];
    foreach ($terms as $t) $termGrades[$t] = compute_term($studentId, $classId, $t, $cs)['grade'];
    $hasAny = array_filter($termGrades, fn($g)=>$g!==null);
    if (!$hasAny) return ['final'=>null,'terms'=>$termGrades];
    $sum=0.0; $wTotal=0.0;
    foreach ($terms as $t) {
        $w=$tw[$t]??(100/count($terms));
        $grade=$termGrades[$t]!==null?(float)$termGrades[$t]:(float)$cs['zero_equiv'];
        $sum+=$grade*$w; $wTotal+=$w;
    }
    return ['final'=>$wTotal>0?(int)round($sum/$wTotal):null,'terms'=>$termGrades];
}
