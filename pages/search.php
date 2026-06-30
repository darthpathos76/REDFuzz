<?php
/**
 * pages/search.php
 * RedFuzz v2.0.0 -- WCH
 *
 * Features:
 *  - Word-level fuzzy scoring via similar_text()
 *  - Exact substring bonus (auto 100%)
 *  - Synonym map expansion (configured in project settings)
 *  - Stopword filtering
 *  - Match highlighting with <mark>
 *  - Results grouped by record with direct data-entry link
 *  - Saved default threshold from project settings
 */

defined('NOAUTH') or define('NOAUTH', false);
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

/** @var \WCH\RedFuzz\RedFuzz $module */
$project_id = (int) PROJECT_ID;

// ---------------------------------------------------------------------------
// 1. Project settings
// ---------------------------------------------------------------------------
$saved_threshold  = (int) ($module->getProjectSetting('default_threshold') ?: 80);
$synonym_map_raw  = (string) ($module->getProjectSetting('synonym_map') ?: '');

// Parse synonym map: each line is a pipe-separated group.
// Build a lookup: every term => [all terms in its group]
$synonym_groups = [];
foreach (explode("\n", $synonym_map_raw) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $terms = array_filter(array_map('trim', explode('|', strtolower($line))));
    if (count($terms) < 2) continue;
    $terms = array_values($terms);
    foreach ($terms as $term) {
        $synonym_groups[$term] = $terms;
    }
}

// ---------------------------------------------------------------------------
// 2. Field list
// ---------------------------------------------------------------------------
$dd = \REDCap::getDataDictionary($project_id, 'array');
$field_options = [];
foreach ($dd as $fname => $fmeta) {
    $field_options[$fname] = ($fmeta['field_label'] !== '') ? $fmeta['field_label'] : $fname;
}

// ---------------------------------------------------------------------------
// 3. Stopwords (English, built-in)
// ---------------------------------------------------------------------------
$stopwords = array_flip([
    'a','an','the','and','or','but','in','on','at','to','for','of','with',
    'by','from','is','was','are','were','be','been','being','have','has',
    'had','do','does','did','will','would','could','should','may','might',
    'shall','can','not','no','nor','so','yet','both','either','neither',
    'as','if','then','than','that','this','these','those','it','its',
    'i','me','my','we','our','you','your','he','his','she','her','they',
    'their','them','what','which','who','whom','when','where','why','how',
]);

// ---------------------------------------------------------------------------
// 4. Inputs
// ---------------------------------------------------------------------------
$is_post       = ($_SERVER['REQUEST_METHOD'] === 'POST');
$keywords_raw  = $is_post ? trim((string)($_POST['keywords'] ?? '')) : '';
$threshold     = $is_post
    ? max(1, min(100, (int)($_POST['threshold'] ?? $saved_threshold)))
    : $saved_threshold;
$posted_fields = $is_post ? (array)($_POST['fields'] ?? []) : [];
$search_all    = in_array('__all__', $posted_fields, true);
$active_fields = $search_all ? array_keys($field_options) : $posted_fields;

// ---------------------------------------------------------------------------
// 5. Keyword parsing + synonym expansion
// ---------------------------------------------------------------------------
// Parse raw input into base keywords, filtering stopwords
$base_keywords = [];
foreach (explode(',', $keywords_raw) as $kw) {
    $kw = strtolower(trim($kw));
    if ($kw === '' || isset($stopwords[$kw])) continue;
    $base_keywords[] = $kw;
}

// Expand each base keyword through the synonym map.
// $expanded_keywords: base_keyword => [base_keyword, synonym1, synonym2, ...]
$expanded_keywords = [];
foreach ($base_keywords as $kw) {
    $expanded_keywords[$kw] = isset($synonym_groups[$kw])
        ? $synonym_groups[$kw]
        : [$kw];
}

// ---------------------------------------------------------------------------
// 6. Scoring function
// ---------------------------------------------------------------------------
/**
 * Score a single field value against one keyword and all its synonyms.
 * Returns ['score' => float, 'matched' => string] where matched is the
 * synonym or keyword that produced the best score.
 *
 * Strategy per candidate word in the value:
 *   a) If keyword is an exact substring of the candidate word -> 100%
 *   b) Otherwise similar_text() percentage
 */
function redfuzz_score_value(string $value, array $synonyms): array
{
    $value_lc = strtolower($value);
    $tokens   = preg_split('/[\s\.,;:!?()\[\]\/\-]+/', $value_lc, -1, PREG_SPLIT_NO_EMPTY);
    // Include full string as a candidate for multi-word phrases
    $candidates = array_merge($tokens, [$value_lc]);

    $best_score   = 0.0;
    $best_matched = '';

    foreach ($synonyms as $syn) {
        foreach ($candidates as $candidate) {
            // Exact substring check
            if (strpos($candidate, $syn) !== false) {
                return ['score' => 100.0, 'matched' => $syn];
            }
            similar_text($candidate, $syn, $pct);
            $pct = round($pct, 1);
            if ($pct > $best_score) {
                $best_score   = $pct;
                $best_matched = $syn;
            }
        }
    }

    return ['score' => $best_score, 'matched' => $best_matched];
}

// ---------------------------------------------------------------------------
// 7. Highlighting function
// ---------------------------------------------------------------------------
/**
 * Wrap all occurrences of any matched synonym in <mark> tags.
 * Works on a case-insensitive word-boundary basis.
 */
function redfuzz_highlight(string $value, array $matched_terms): string
{
    $safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    foreach ($matched_terms as $term) {
        if ($term === '') continue;
        $pattern = '/(' . preg_quote(htmlspecialchars($term, ENT_QUOTES, 'UTF-8'), '/') . ')/iu';
        $safe = preg_replace($pattern, '<mark>$1</mark>', $safe);
    }
    return $safe;
}

// ---------------------------------------------------------------------------
// 8. Run search
// ---------------------------------------------------------------------------
// $grouped_results: record_id => ['fields' => [...], 'top_score' => float]
$grouped_results = [];

if ($is_post && !empty($base_keywords) && !empty($active_fields)) {
    $data = \REDCap::getData($project_id, 'array');

    foreach ($data as $record_id => $events) {
        foreach ($events as $event_id => $row) {
            foreach ($row as $fname => $val) {
                if (!in_array($fname, $active_fields, true)) continue;
                if (!is_string($val) || trim($val) === '') continue;

                // Score against each keyword (all synonyms), keep best per keyword
                $field_best_score   = 0.0;
                $field_matched_terms = [];

                foreach ($expanded_keywords as $kw => $synonyms) {
                    $result = redfuzz_score_value($val, $synonyms);
                    if ($result['score'] >= $threshold) {
                        if ($result['score'] > $field_best_score) {
                            $field_best_score = $result['score'];
                        }
                        if ($result['matched'] !== '') {
                            $field_matched_terms[] = $result['matched'];
                            // Also add the original keyword so both get highlighted
                            if ($result['matched'] !== $kw) {
                                $field_matched_terms[] = $kw;
                            }
                        }
                    }
                }

                if ($field_best_score >= $threshold) {
                    if (!isset($grouped_results[$record_id])) {
                        $grouped_results[$record_id] = [
                            'record_id' => $record_id,
                            'fields'    => [],
                            'top_score' => 0.0,
                        ];
                    }
                    $grouped_results[$record_id]['fields'][] = [
                        'fname'   => $fname,
                        'label'   => $field_options[$fname] ?? $fname,
                        'value'   => $val,
                        'score'   => $field_best_score,
                        'matched' => array_unique($field_matched_terms),
                    ];
                    if ($field_best_score > $grouped_results[$record_id]['top_score']) {
                        $grouped_results[$record_id]['top_score'] = $field_best_score;
                    }
                }
            }
        }
    }

    // Sort records by top score descending
    usort($grouped_results, fn($a, $b) => $b['top_score'] <=> $a['top_score']);

    // Sort fields within each record by score descending
    foreach ($grouped_results as &$rec) {
        usort($rec['fields'], fn($a, $b) => $b['score'] <=> $a['score']);
    }
    unset($rec);
}

// ---------------------------------------------------------------------------
// 9. Build record link helper
// ---------------------------------------------------------------------------
function redfuzz_record_link(int $project_id, string $record_id): string
{
    return APP_PATH_WEBROOT . 'DataEntry/record_home.php?pid=' . $project_id
        . '&id=' . urlencode($record_id);
}

// ---------------------------------------------------------------------------
// 10. Synonym map summary for display
// ---------------------------------------------------------------------------
$synonym_display = [];
foreach ($synonym_groups as $term => $group) {
    $key = implode('|', $group);
    $synonym_display[$key] = $group;
}
$synonym_display = array_values($synonym_display);

?>
<style>
.rf-wrap         { max-width: 960px; margin: 20px; }
.rf-section      { margin-bottom: 16px; }
.rf-field-box    { max-height: 200px; overflow-y: auto; border: 1px solid #ccc;
                   padding: 8px; margin-top: 4px; background: #fafafa; }
.rf-record-block { border: 1px solid #ddd; border-radius: 4px;
                   margin-bottom: 12px; overflow: hidden; }
.rf-record-hdr   { background: #e8f0fe; padding: 8px 12px; font-weight: bold;
                   display: flex; justify-content: space-between; align-items: center; }
.rf-record-hdr a { color: #1a0dab; text-decoration: none; }
.rf-record-hdr a:hover { text-decoration: underline; }
.rf-field-row    { padding: 8px 12px; border-top: 1px solid #eee; font-size: 13px; }
.rf-field-row:nth-child(even) { background: #f9f9f9; }
.rf-field-name   { color: #555; font-size: 11px; margin-bottom: 2px; }
.rf-field-val    { line-height: 1.5; }
.rf-score-badge  { display: inline-block; background: #1a73e8; color: #fff;
                   border-radius: 10px; padding: 1px 7px; font-size: 11px;
                   margin-left: 8px; }
.rf-syn-tag      { display: inline-block; background: #f0f0f0; border: 1px solid #ccc;
                   border-radius: 3px; padding: 1px 5px; font-size: 11px;
                   margin: 1px; }
mark             { background: #fff176; padding: 0 1px; border-radius: 2px; }
</style>

<div class="rf-wrap">
    <h2>RedFuzz Search</h2>

    <?php if (!empty($synonym_display)): ?>
    <div class="rf-section" style="font-size:12px; color:#555; background:#f8f8f8;
         border:1px solid #e0e0e0; border-radius:4px; padding:8px 12px;">
        <strong>Active synonym groups:</strong><br>
        <?php foreach ($synonym_display as $group): ?>
            <?php foreach ($group as $t): ?>
                <span class="rf-syn-tag"><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
            <span style="margin: 0 6px; color:#aaa;">|</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" style="margin-top:16px;">
        <input type="hidden" name="redcap_csrf_token" value="<?= \System::getCsrfToken() ?>">

        <div class="rf-section">
            <label><strong>Keywords</strong> <span style="font-weight:normal;">(comma-separated; stopwords ignored)</span></label><br>
            <input type="text" name="keywords"
                   value="<?= htmlspecialchars($keywords_raw, ENT_QUOTES, 'UTF-8') ?>"
                   style="width:100%; padding:6px; margin-top:4px; font-size:14px;">
        </div>

        <div class="rf-section">
            <label><strong>Match threshold %</strong></label><br>
            <input type="number" name="threshold" min="1" max="100"
                   value="<?= $threshold ?>"
                   style="width:80px; padding:6px; margin-top:4px;">
        </div>

        <div class="rf-section">
            <label><strong>Fields to search</strong></label>
            <div class="rf-field-box">
                <label style="display:block; margin-bottom:4px;">
                    <input type="checkbox" name="fields[]" value="__all__"
                        <?= (!$is_post || $search_all) ? 'checked' : '' ?>>
                    <em>All fields</em>
                </label>
                <hr style="margin:4px 0;">
                <?php foreach ($field_options as $fname => $flabel): ?>
                    <label style="display:block; margin-bottom:2px;">
                        <input type="checkbox" name="fields[]"
                               value="<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>"
                            <?= (!$is_post || $search_all || in_array($fname, $posted_fields, true)) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($flabel, ENT_QUOTES, 'UTF-8') ?>
                        <small style="color:#888;">(<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>)</small>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Search</button>
    </form>

<?php if ($is_post): ?>
    <hr style="margin:24px 0;">

    <?php if (empty($base_keywords)): ?>
        <div class="alert alert-warning">Enter at least one non-stopword keyword.</div>

    <?php elseif (empty($grouped_results)): ?>
        <div class="alert alert-info">
            No matches found for <strong><?= htmlspecialchars($keywords_raw, ENT_QUOTES, 'UTF-8') ?></strong>
            at threshold <?= $threshold ?>%.
        </div>

    <?php else: ?>
        <p>
            Found <strong><?= count($grouped_results) ?></strong> record(s) matching
            <strong><?= htmlspecialchars($keywords_raw, ENT_QUOTES, 'UTF-8') ?></strong>
            at threshold <?= $threshold ?>%.
        </p>

        <?php foreach ($grouped_results as $rec): ?>
            <div class="rf-record-block">
                <div class="rf-record-hdr">
                    <span>
                        Record:
                        <a href="<?= redfuzz_record_link($project_id, (string)$rec['record_id']) ?>"
                           target="_blank">
                            <?= htmlspecialchars((string)$rec['record_id'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </span>
                    <span>
                        <?= count($rec['fields']) ?> field match(es)
                        <span class="rf-score-badge">top <?= $rec['top_score'] ?>%</span>
                    </span>
                </div>

                <?php foreach ($rec['fields'] as $fmatch): ?>
                    <div class="rf-field-row">
                        <div class="rf-field-name">
                            <?= htmlspecialchars($fmatch['label'], ENT_QUOTES, 'UTF-8') ?>
                            <span style="color:#aaa;">(<?= htmlspecialchars($fmatch['fname'], ENT_QUOTES, 'UTF-8') ?>)</span>
                            <span class="rf-score-badge"><?= $fmatch['score'] ?>%</span>
                            <?php if (!empty($fmatch['matched'])): ?>
                                &nbsp;matched via:
                                <?php foreach ($fmatch['matched'] as $mt): ?>
                                    <span class="rf-syn-tag"><?= htmlspecialchars($mt, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="rf-field-val">
                            <?= redfuzz_highlight($fmatch['value'], $fmatch['matched']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php'; ?>
