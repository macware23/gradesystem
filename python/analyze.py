#!/usr/bin/env python3
"""
GradeFlow - Analysis Engine (Ollama/phi-3 powered)
Statistical computation is always performed locally.
Ollama (phi-3) is used to generate natural-language actions and narratives.
Falls back to built-in text generation when Ollama is unreachable.
"""
import sys
import json
import re
import statistics
import urllib.request
import urllib.error

# ── Ollama configuration ──────────────────────────────────────────────
OLLAMA_URL     = "http://localhost:11434/api/generate"
OLLAMA_MODEL   = "phi3.5"
OLLAMA_TIMEOUT = 120  # seconds per request (phi3.5 on CPU needs time)


def ollama_generate(prompt, max_tokens=400):
    """Call local Ollama API. Returns text response or None on any failure."""
    body = json.dumps({
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.4, "num_predict": max_tokens},
    }).encode()
    req = urllib.request.Request(
        OLLAMA_URL,
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=OLLAMA_TIMEOUT) as resp:
            data = json.loads(resp.read())
            text = data.get("response", "").strip()
            return text if text else None
    except Exception:
        return None


def parse_numbered_list(text):
    """Parse a numbered or bulleted list response into a list of strings."""
    lines = []
    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue
        cleaned = re.sub(r'^(\d+[\.\)]\s*|[-*•]\s*)', '', line).strip()
        if cleaned:
            lines.append(cleaned)
    return lines if lines else [text]


# ─────────────────────────────────────────────────────────────────────
# INDIVIDUAL STUDENT ANALYSIS
# ─────────────────────────────────────────────────────────────────────

def analyze_local(data, use_ollama=True):
    passing  = float(data.get("student", {}).get("passing_grade", 75))
    terms    = dict(data.get("terms") or {})
    graded   = {k: v for k, v in terms.items() if v is not None}
    final    = data.get("final")
    criteria = data.get("criteria") or []
    name     = data.get("student", {}).get("name", "Student")

    risk    = 0.0
    reasons = []

    # Reference grade
    ref = final if final is not None else (
        statistics.mean(graded.values()) if graded else None)

    # ── Standing vs passing mark ──────────────────────────────────────
    grade_status = "passing"
    if ref is not None:
        if ref < passing:
            gap = passing - ref
            risk += min(45, gap * 3)
            grade_status = "failing"
            reasons.append(
                f"Current grade ({ref:.1f}) is {gap:.1f} points below passing ({passing:.0f}).")
        elif ref < passing + 5:
            risk += 18
            grade_status = "borderline"
            reasons.append(
                f"Current grade ({ref:.1f}) is narrowly above passing — one bad score could drop it.")

    # ── Term trend ────────────────────────────────────────────────────
    trend_note = None
    ordered = [v for v in terms.values() if v is not None]
    if len(ordered) >= 2:
        delta = ordered[-1] - ordered[0]
        if delta <= -5:
            risk += 20
            trend_note = "declining"
            reasons.append(
                f"Grades are dropping across terms ({ordered[0]:.1f} → {ordered[-1]:.1f}).")
        elif delta >= 5:
            trend_note = "improving"
        else:
            trend_note = "steady"

    # ── Inconsistency ────────────────────────────────────────────────
    pcts = [c["pct"] for c in criteria if c.get("pct") is not None]
    if len(pcts) >= 3:
        try:
            sd = statistics.pstdev(pcts)
            if sd > 0.22:
                risk += 12
                reasons.append(
                    "Performance is uneven — strong in some areas, weak in others.")
        except statistics.StatisticsError:
            pass

    # ── Missing work ─────────────────────────────────────────────────
    missing = [c["name"] for c in criteria if c.get("pct") is None]
    if missing:
        risk += min(15, len(missing) * 5)
        reasons.append(
            f"{len(missing)} component(s) have no score yet — zeros are dragging the grade down.")

    risk  = max(0, min(100, round(risk)))
    level = "high" if risk >= 55 else ("medium" if risk >= 25 else "low")

    # ── Weak areas ranked by weighted impact ─────────────────────────
    scored = [c for c in criteria if c.get("pct") is not None]
    weak   = []
    for c in scored:
        weak.append({
            "term":   c.get("term", ""),
            "name":   c["name"],
            "pct":    round(c["pct"] * 100, 1),
            "weight": c.get("weight", 0),
            "impact": round((1 - c["pct"]) * float(c.get("weight", 0)), 1),
        })
    weak.sort(key=lambda w: w["impact"], reverse=True)
    weak = weak[:5]

    # ── Action steps (Ollama or statistical fallback) ─────────────────
    if use_ollama:
        actions = ollama_student_actions(level, trend_note, weak, missing, ref, passing, grade_status, name)
        engine  = "ollama"
    else:
        actions = student_actions(level, trend_note, weak, missing, ref, passing, grade_status)
        engine  = "local"

    return {
        "engine":          engine,
        "risk_score":      risk,
        "risk_level":      level,
        "grade_status":    grade_status,
        "reasons":         reasons,
        "trend":           trend_note,
        "reference_grade": round(ref, 1) if ref is not None else None,
        "weak_areas":      weak,
        "missing":         missing,
        "actions":         actions,
        "recommendations": actions,   # kept for backward compat
    }


# ─────────────────────────────────────────────────────────────────────
# OLLAMA-POWERED TEXT GENERATION
# ─────────────────────────────────────────────────────────────────────

def ollama_student_actions(level, trend, weak, missing, ref, passing, grade_status, name="Student"):
    """Generate student action steps via Ollama phi-3. Falls back to statistical version."""
    weak_str = ", ".join(
        f"{w['name']} ({w['pct']:.0f}%, weight {w['weight']:.0f}%)"
        for w in weak[:3]
    ) if weak else "none identified"
    missing_str = (
        ", ".join(missing[:3]) + (" and others" if len(missing) > 3 else "")
    ) if missing else "none"
    ref_str = f"{ref:.1f}" if ref is not None else "not yet computed"

    prompt = (
        f"Student grade intervention. Be brief.\n"
        f"Name: {name} | Grade: {ref_str} | Passing: {passing:.0f} | Status: {grade_status} | "
        f"Trend: {trend or 'N/A'} | Risk: {level}\n"
        f"Weak: {weak_str} | Missing: {missing_str}\n\n"
        f"Give 3 short numbered action steps the student should do now to improve their grade. "
        f"Each step max 2 sentences. Number them 1. 2. 3."
    )
    text = ollama_generate(prompt, max_tokens=180)
    if text:
        actions = parse_numbered_list(text)
        if actions:
            return actions
    return student_actions(level, trend, weak, missing, ref, passing, grade_status)


def ollama_class_narrative(results, summary, passing):
    """Generate class-level narrative via Ollama phi-3. Falls back to statistical version."""
    total    = summary.get("total", 0)
    graded   = summary.get("graded", 0)
    failing  = summary.get("failing", 0)
    avg      = summary.get("avg_grade")
    rate     = summary.get("passing_rate")
    top_weak = summary.get("top_weak", [])

    high_risk = [r["name"] for r in results if r.get("risk_level") == "high"]
    med_count = sum(1 for r in results if r.get("risk_level") == "medium")

    high_str = (
        ", ".join(high_risk[:3]) + (" and others" if len(high_risk) > 3 else "")
    ) if high_risk else "none"
    weak_str = ", ".join(w.split(":")[-1] for w in top_weak[:3]) if top_weak else "none identified"
    avg_str  = f"{avg:.1f}" if avg is not None else "N/A"

    prompt = (
        f"Class performance summary for teacher. Be concise.\n"
        f"Students: {total} total, {graded} graded | Failing: {failing} | Passing rate: {rate}% | "
        f"Average: {avg_str} (passing: {passing:.0f})\n"
        f"High-risk: {high_str} | Medium-risk: {med_count} | Weak areas: {weak_str}\n\n"
        f"Write a 3-sentence teacher summary and 2 concrete actions for this week."
    )
    text = ollama_generate(prompt, max_tokens=200)
    if text:
        return text
    return class_narrative(results, summary, passing)


# ─────────────────────────────────────────────────────────────────────
# STATISTICAL TEXT GENERATION (fallback)
# ─────────────────────────────────────────────────────────────────────

def student_actions(level, trend, weak, missing, ref, passing, status):
    """Statistical fallback: returns concrete action steps for the student."""
    acts = []

    if missing:
        shown = ", ".join(missing[:3]) + (" and others" if len(missing) > 3 else "")
        acts.append(
            f"Submit missing work immediately: {shown}. Even a late or partial submission "
            f"is better than a zero — approach your teacher now and ask about late-submission options.")

    if weak:
        top = weak[0]
        score_gap = 100 - top["pct"]
        acts.append(
            f"Focus first on {top['name']} ({top['pct']:.0f}% average, {top['weight']:.0f}% weight). "
            f"Closing that {score_gap:.0f}% gap here has the biggest effect on your final grade. "
            f"Study past questions, re-read the relevant chapter sections, and ask your teacher "
            f"to explain any items you got wrong.")
        if len(weak) > 1:
            second = weak[1]
            acts.append(
                f"Second priority: {second['name']} ({second['pct']:.0f}% average). "
                f"Dedicate at least one focused study session to this per week. "
                f"Form a study group or use practice exercises to strengthen this area.")

    if status == "failing" and ref is not None:
        needed = passing - ref
        acts.append(
            f"You need to gain approximately {needed:.1f} points to reach the passing mark. "
            f"The fastest way is to improve the heaviest-weighted components first. "
            f"Talk to your teacher about extra credit or make-up activities if available.")
    elif status == "borderline":
        acts.append(
            f"Your grade is close to the passing line — do not miss any remaining "
            f"activities or exams. Attend all classes, complete every assignment on time, "
            f"and aim for full marks on smaller tasks like quizzes and recitations.")

    if trend == "declining":
        acts.append(
            f"Your grades have been dropping — act now before the trend continues. "
            f"Review what changed since the first term: attendance, study habits, "
            f"or workload. Consider visiting your teacher during consultation hours "
            f"to get back on track.")
    elif trend == "improving":
        acts.append(
            f"Your grades are moving upward — keep the momentum. "
            f"Continue the study habits that are working, and maintain your attendance record.")

    if level == "high":
        acts.append(
            f"Create a daily study schedule and stick to it. Even 30 minutes of focused "
            f"review every day is more effective than cramming before exams. "
            f"Use your class notes, past tests, and textbook exercises.")
    elif level == "medium":
        acts.append(
            f"Stay consistent with your study routine. Review class notes after each session "
            f"and do practice problems regularly so material stays fresh.")
    else:
        acts.append(
            f"You are on a good track. Keep attending classes, submitting work on time, "
            f"and reviewing material regularly to maintain your standing.")

    return acts


# ─────────────────────────────────────────────────────────────────────
# CLASS-LEVEL NARRATIVE SUMMARY (statistical fallback)
# ─────────────────────────────────────────────────────────────────────

def class_narrative(results, summary, passing):
    """Statistical fallback: teacher-facing class summary paragraph."""
    total    = summary.get("total", 0)
    graded   = summary.get("graded", 0)
    failing  = summary.get("failing", 0)
    at_risk  = summary.get("at_risk", 0)
    avg      = summary.get("avg_grade")
    rate     = summary.get("passing_rate")
    top_weak = summary.get("top_weak", [])

    lines = []

    if avg is not None:
        standing = "above" if avg >= passing + 5 else ("near" if avg >= passing else "below")
        lines.append(
            f"The class average of {avg:.1f} is {standing} the passing mark of {passing:.0f}. "
            f"{graded} of {total} students have been graded; "
            f"{'all' if failing == 0 else str(failing)} {'are' if failing != 1 else 'is'} currently "
            f"{'passing' if failing == 0 else f'below the passing mark ({rate}% passing rate)'}.")

    high_risk = [r for r in results if r.get("risk_level") == "high"]
    med_risk  = [r for r in results if r.get("risk_level") == "medium"]
    if high_risk:
        names = ", ".join(r["name"] for r in high_risk[:3])
        extra = f" and {len(high_risk)-3} others" if len(high_risk) > 3 else ""
        lines.append(
            f"{len(high_risk)} student(s) are HIGH intervention priority: {names}{extra}. "
            f"These students need immediate one-on-one attention.")
    if med_risk:
        lines.append(
            f"{len(med_risk)} student(s) are at MEDIUM risk and should be monitored closely "
            f"over the next two weeks.")

    if top_weak:
        weak_names = [w.split(":")[-1] for w in top_weak[:3]]
        lines.append(
            f"The most common weak areas across the class are: {', '.join(weak_names)}. "
            f"Consider dedicating extra class time or supplementary materials to these topics.")

    if failing > 0 or len(high_risk) > 0:
        lines.append(
            f"Recommended class-level actions: (1) conduct a brief diagnostic quiz on the "
            f"weakest topics to pinpoint specific gaps; (2) schedule a remediation session "
            f"or review class before the next major assessment; (3) offer optional consultation "
            f"hours for struggling students.")
    elif at_risk > 0:
        lines.append(
            f"While most students are passing, {at_risk} need monitoring. "
            f"Short weekly formative checks (quizzes, recitations) will help catch "
            f"any slide early.")
    else:
        lines.append(
            f"The class is performing well overall. Consider enrichment activities "
            f"to keep engagement high and challenge advanced learners.")

    lines.append(
        f"This analysis is generated automatically from grade data. "
        f"Use it as a starting point for professional judgment, not as a final evaluation.")

    return " ".join(lines)


def main():
    try:
        raw = sys.stdin.read()
        data = json.loads(raw) if raw.strip() else {}
    except json.JSONDecodeError as e:
        print(json.dumps({"local": {"error": f"Invalid input JSON: {e}"}}))
        return

    # Batch mode: class narrative generation
    if "batch" in data:
        results  = []
        passing  = float(data.get("passing_grade", 75))
        for s in data["batch"]:
            res = analyze_local(s, use_ollama=False)  # statistical only for batch items
            res["name"] = s.get("student", {}).get("name", "")
            results.append(res)
        summary   = data.get("summary", {})
        narrative = ollama_class_narrative(results, summary, passing)
        print(json.dumps({"ok": True, "narrative": narrative}))
        return

    # Single student mode
    # skip_ollama=True is set by analyze_class.php for per-student batch calls
    use_ollama = not data.get("skip_ollama", False)
    print(json.dumps({"local": analyze_local(data, use_ollama=use_ollama)}))


if __name__ == "__main__":
    main()
