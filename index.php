<?php

/**
 * Better Search - Standalone Add/Edit Records Replacement Page
 *
 * @var \UWMadison\BetterSearch\BetterSearch $module
 * @var int $project_id
 */

if (empty($project_id)) {
    echo '<div class="alert alert-danger">Better Search must be accessed within a REDCap project context.</div>';
    return;
}

$context = $module->getProjectContext($project_id, true);
$fieldConfig = $module->getFieldConfiguration($project_id, $context, false);
$module->primeSearchEnvironmentCache($project_id, $context, $fieldConfig);

$selectedArm = 1;
if ($context['multiple_arms'] && !empty($context['arms'])) {
    $requestedArm = isset($_GET['arm']) ? (int) $_GET['arm'] : 0;
    $selectedArm = isset($context['arms'][$requestedArm])
        ? $requestedArm
        : (int) array_key_first($context['arms']);
}

$counts = $module->getRecordCounts($project_id, $context, $selectedArm);
$devLimitReached = $context['status'] === 0
    && $context['dev_record_limit'] > 0
    && $counts['total'] >= $context['dev_record_limit']
    && !(defined('SUPER_USER') && SUPER_USER);

$canCreateRecord = (!isset($context['user_rights']['record_create']) || (string) $context['user_rights']['record_create'] !== '0')
    && !$devLimitReached;

$hasDags = !empty($context['dags']);
$userDagId = $context['user_dag_id'];
$userDagName = ($userDagId && isset($context['dags'][$userDagId])) ? $context['dags'][$userDagId] : null;
$canSelectDag = $hasDags && empty($userDagId);

$hasCustomLabel = !empty($context['custom_record_label']);
$hasSecondaryPk = !empty($context['secondary_pk']);

$nativeRecordHomeUrl = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid={$project_id}&native=1"
    . ($context['multiple_arms'] ? "&arm={$selectedArm}" : "");

$tier1Summary = [];
foreach ($fieldConfig['base_tier1_fields'] as $fname => $reason) {
    $meta = $fieldConfig['field_meta'][$fname] ?? [];
    $tier1Summary[] = [
        'name' => $fname,
        'label' => $meta['label'] ?? $fname,
        'reason' => $reason,
    ];
}

$uniqueFields = $fieldConfig['unique_fields'] ?? [$context['pk']];

$seedLocators = (object) [];
if (($counts['total'] ?? 0) <= 1000) {
    $seedRes = $module->handleFetchLocators($project_id, ['limit' => 1000]);
    $seedLocators = $seedRes['locators'] ?? (object) [];
}

$remainingFieldCount = count($fieldConfig['all_searchable_fields']) - count($fieldConfig['base_tier1_fields']);
if ($remainingFieldCount < 0)
    $remainingFieldCount = 0;

$enableDeepChecked = !isset($_COOKIE['better_search_enable_deep']) || $_COOKIE['better_search_enable_deep'] !== '0';
$showRecentChecked = !isset($_COOKIE['better_search_show_recent']) || $_COOKIE['better_search_show_recent'] !== '0';

$defaultDagCookieKey = 'better_search_default_dag_' . (int) $project_id;
$defaultDagFilter = isset($_COOKIE[$defaultDagCookieKey]) ? trim((string) $_COOKIE[$defaultDagCookieKey]) : '';
if ($defaultDagFilter !== '' && $defaultDagFilter !== 'none' && !isset($context['dags'][(int) $defaultDagFilter]))
    $defaultDagFilter = '';

$fuzzyThreshold = isset($_COOKIE['better_search_fuzzy_threshold']) ? (int) $_COOKIE['better_search_fuzzy_threshold'] : 100;
if ($fuzzyThreshold < 50 || $fuzzyThreshold > 100)
    $fuzzyThreshold = 100;

$savedPageSize = isset($_COOKIE['better_search_page_size']) ? (int) $_COOKIE['better_search_page_size'] : 20;
if ($savedPageSize < 1 || $savedPageSize > 500)
    $savedPageSize = 20;

$msgParam = isset($_GET['msg']) ? trim((string) $_GET['msg']) : '';

$module->initializeJavascriptModuleObject();
$jsObjectName = $module->getJavascriptModuleObjectName();
?>

<link rel="stylesheet" href="<?= htmlspecialchars($module->getUrl('index.css'), ENT_QUOTES, 'UTF-8') ?>">

<div class="bs-wrap">
    <!-- Native REDCap Page Header -->
    <div class="bs-top-row">
        <div class="projhdr">
            <i class="fas fa-search"></i> Add / Edit Records
        </div>
        <a id="bs-classic-view-link" href="<?= htmlspecialchars($nativeRecordHomeUrl, ENT_QUOTES, 'UTF-8') ?>" class="bs-btn bs-btn-outline" title="Switch to REDCap's standard Add/Edit Records page">
            <i class="fas fa-exchange-alt"></i> Classic View
        </a>
    </div>

    <div class="bs-intro">
        Search for an existing record below<?= $canCreateRecord ? ' or create a new record' : '' ?>.
        Dates (e.g. <code>MM/DD/YYYY</code>), phone numbers, emails, multi-word queries, and multiple-choice labels are matched automatically.
    </div>

    <?php if ($msgParam === 'delete'): ?>
        <div id="bs-delete-notice" class="darkgreen" style="margin:10px 0 14px;max-width:960px;">
            <img src="<?= APP_PATH_IMAGES ?>tick.png" alt="" style="vertical-align:middle;position:relative;top:-1px;margin-right:4px;">
            <b><?= htmlspecialchars($context['pk_label'], ENT_QUOTES, 'UTF-8') ?> successfully deleted.</b>
        </div>
    <?php endif; ?>

    <?php $module->renderDevelopmentNotices($context, $counts); ?>

    <!-- Multi-Arm Selector Bar (Longitudinal Multi-Arm Projects) -->
    <?php if ($context['multiple_arms'] && !empty($context['arms'])): ?>
        <div class="bs-arm-bar">
            <span class="bs-arm-label">Arm:</span>
            <?php foreach ($context['arms'] as $armNum => $armInfo): ?>
                <?php
                $armUrl = $module->getUrl('index.php') . '&arm=' . (int) $armNum;
                $isActive = ((int) $armNum === (int) $selectedArm);
                $armRecCount = number_format($counts['arms'][(int) $armNum] ?? 0);
                ?>
                <a href="<?= htmlspecialchars($armUrl, ENT_QUOTES, 'UTF-8') ?>"
                    class="bs-arm-tab <?= $isActive ? 'active' : '' ?>"
                    data-arm="<?= (int) $armNum ?>"
                    data-arm-name="<?= htmlspecialchars($armInfo['name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-arm-count="<?= htmlspecialchars($armRecCount, ENT_QUOTES, 'UTF-8') ?>">
                    Arm <?= (int) $armNum ?>: <?= htmlspecialchars($armInfo['name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Main REDCap-styled Box -->
    <div class="bs-panel">
        <!-- Record Counts Summary -->
        <div class="bs-counts-row">
            <span>Total records in project: <strong><?= number_format($counts['total']) ?></strong></span>
            <?php if ($counts['arm'] !== null && isset($context['arms'][$selectedArm])): ?>
                <span id="bs-arm-count-wrap">
                    <span id="bs-arm-count-label">Total in Arm <?= (int) $selectedArm ?> (<?= htmlspecialchars($context['arms'][$selectedArm]['name'], ENT_QUOTES, 'UTF-8') ?>):</span>
                    <strong id="bs-arm-count-val"><?= number_format($counts['arm']) ?></strong>
                </span>
            <?php endif; ?>
            <?php if ($counts['dag'] !== null): ?>
                <span>
                    Total in your Data Access Group (<?= htmlspecialchars((string) $userDagName, ENT_QUOTES, 'UTF-8') ?>):
                    <strong><?= number_format($counts['dag']) ?></strong>
                </span>
            <?php endif; ?>
        </div>

        <!-- Row 1: Search Records -->
        <div class="bs-section-row">
            <div class="bs-row-label">
                Search existing records
                <div class="bs-row-label-sub">
                    Press <strong>Enter</strong> or click <strong>Search</strong>
                </div>
            </div>
            <div class="bs-row-content">
                <form id="bs-search-form" autocomplete="off">
                    <div class="bs-search-bar">
                        <input
                            type="text"
                            id="bs-search-input"
                            name="bs_search_no_autocomplete"
                            class="bs-input-text"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                            placeholder="Search by <?= htmlspecialchars($context['pk_label'], ENT_QUOTES, 'UTF-8') ?><?= $hasSecondaryPk ? ', ' . htmlspecialchars($context['secondary_pk_label'], ENT_QUOTES, 'UTF-8') : '' ?>, identifier, date, or any field value..."
                            autofocus>
                        <?php if ($canSelectDag): ?>
                            <select id="bs-dag-filter" class="bs-select" title="Filter search results by Data Access Group">
                                <option value="" <?= $defaultDagFilter === '' ? ' selected' : '' ?>>All Data Access Groups</option>
                                <option value="none" <?= $defaultDagFilter === 'none' ? ' selected' : '' ?>>[No DAG assigned]</option>
                                <?php foreach ($context['dags'] as $gid => $gname): ?>
                                    <option value="<?= (int) $gid ?>" <?= $defaultDagFilter === (string) (int) $gid ? ' selected' : '' ?>><?= htmlspecialchars($gname, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" id="bs-search-btn" class="bs-btn bs-btn-search">
                            <i class="fas fa-search"></i> <span id="bs-search-btn-label">Search</span>
                        </button>
                    </div>
                </form>

                <div id="bs-quick-open-banner" class="bs-quick-open-banner" style="display:none;">
                    <div class="bs-quick-open-info">
                        <i class="fas fa-arrow-circle-right bs-qo-icon"></i>
                        <span class="bs-qo-lead">Direct Match:</span>
                        <strong id="bs-qo-id" class="bs-qo-id"></strong>
                        <span id="bs-qo-label" class="bs-qo-label"></span>
                        <span id="bs-qo-reason" class="bs-qo-reason"></span>
                    </div>
                    <button type="button" id="bs-quick-open-btn" class="bs-btn bs-btn-quick-open" title="Open record immediately (Shift + Enter)">
                        <i class="fas fa-external-link-alt"></i> Open Record <kbd class="bs-kbd">Shift ↵</kbd>
                    </button>
                </div>

                <div id="bs-chip-bar" class="bs-chip-bar"></div>

                <div id="bs-recent-records-row" class="bs-recent-row" style="display:none;"></div>

                <div class="bs-search-options">
                    <button type="button" id="bs-toggle-settings-btn" class="bs-link-btn">
                        <i class="fas fa-sliders-h"></i> <span id="bs-toggle-settings-label">User settings</span>
                    </button>
                    <span id="bs-active-settings-badges" class="bs-settings-badges"></span>
                    <button type="button" id="bs-toggle-priority-btn" class="bs-link-btn" data-count="<?= count($tier1Summary) ?>">
                        Show fast search fields (<?= count($tier1Summary) ?>)
                    </button>
                </div>

                <div id="bs-settings-drawer" class="bs-settings-drawer">
                    <div class="bs-settings-title">
                        <i class="fas fa-user-cog"></i> User Search Settings
                    </div>
                    <div class="bs-settings-grid">
                        <div class="bs-setting-item">
                            <label class="bs-checkbox-label">
                                <input type="checkbox" id="bs-enable-deep" <?= $enableDeepChecked ? ' checked' : '' ?>>
                                Include deep search across all project fields (<?= $remainingFieldCount ?> additional fields)
                            </label>
                        </div>
                        <div class="bs-setting-item">
                            <label class="bs-checkbox-label">
                                <input type="checkbox" id="bs-show-recent" <?= $showRecentChecked ? ' checked' : '' ?>>
                                Show recently viewed records
                            </label>
                        </div>
                        <?php if ($canSelectDag): ?>
                            <div class="bs-setting-item">
                                <label for="bs-default-dag-setting" class="bs-setting-label">Default search Data Access Group:</label>
                                <select id="bs-default-dag-setting" class="bs-select bs-select-sm">
                                    <option value="" <?= $defaultDagFilter === '' ? ' selected' : '' ?>>All Data Access Groups</option>
                                    <option value="none" <?= $defaultDagFilter === 'none' ? ' selected' : '' ?>>[No DAG assigned]</option>
                                    <?php foreach ($context['dags'] as $gid => $gname): ?>
                                        <option value="<?= (int) $gid ?>" <?= $defaultDagFilter === (string) (int) $gid ? ' selected' : '' ?>><?= htmlspecialchars($gname, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="bs-setting-item bs-setting-fuzzy">
                            <label for="bs-fuzzy-threshold" class="bs-setting-label">Fuzzy search match threshold:</label>
                            <input
                                type="range"
                                id="bs-fuzzy-threshold"
                                class="bs-fuzzy-slider"
                                min="50"
                                max="100"
                                step="5"
                                value="<?= (int) $fuzzyThreshold ?>">
                            <strong id="bs-fuzzy-threshold-val"><?= $fuzzyThreshold >= 100 ? '100% (Off)' : ($fuzzyThreshold . '%') ?></strong>
                        </div>
                    </div>
                </div>

                <div id="bs-priority-drawer" class="bs-priority-drawer">
                    <div style="margin-bottom:4px;font-weight:700;color:#333;">
                        Fast Search Fields (Date, Phone, and Email fields are also included automatically when detected):
                    </div>
                    <?php foreach ($tier1Summary as $item): ?>
                        <span class="bs-priority-chip">
                            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                            <code>[<?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?>]</code>
                            <em>(<?= htmlspecialchars($item['reason'], ENT_QUOTES, 'UTF-8') ?>)</em>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Row 2: Create / Add New Record -->
        <?php if ($canCreateRecord || !$context['auto_inc_set']): ?>
            <div class="bs-section-row">
                <div class="bs-row-label">
                    <?php if ($context['auto_inc_set']): ?>
                        Add new record
                        <div class="bs-row-label-sub">
                            Creates a new <?= htmlspecialchars($context['pk_label'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($context['multiple_arms']): ?>
                                <span id="bs-create-arm-label">in Arm <?= (int) $selectedArm ?></span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?= $canCreateRecord ? 'Enter a new or existing' : 'Open an existing' ?>
                        <?= htmlspecialchars($context['pk_label'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>
                <div class="bs-row-content">
                    <div class="bs-create-controls">
                        <?php if ($context['auto_inc_set'] && $canCreateRecord): ?>
                            <button type="button" id="bs-add-record-btn" class="bs-btn bs-btn-add">
                                <i class="fas fa-plus"></i>
                                Add new record<?= $context['multiple_arms'] ? ' for this arm' : '' ?>
                            </button>
                        <?php else: ?>
                            <form id="bs-manual-id-form" style="display:inline-flex;gap:8px;align-items:center;margin:0;">
                                <input
                                    type="text"
                                    id="bs-manual-id-input"
                                    class="bs-input-text"
                                    style="width:180px;min-width:140px;flex:none;"
                                    placeholder="<?= htmlspecialchars($context['pk_label'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="bs-btn bs-btn-add">
                                    <i class="fas fa-arrow-right"></i> <?= $canCreateRecord ? 'Create / Open' : 'Open' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canSelectDag && $canCreateRecord): ?>
                            <select id="bs-new-record-dag" class="bs-select" title="Optionally assign new record to a Data Access Group when saved">
                                <option value="">No DAG Assignment</option>
                                <?php foreach ($context['dags'] as $gid => $gname): ?>
                                    <option value="<?= (int) $gid ?>"><?= htmlspecialchars($gname, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div id="bs-create-error" class="bs-error-msg"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Search Results Box -->
    <div id="bs-results-box" class="bs-results-box">
        <div class="bs-results-header">
            <div class="bs-badge-group" id="bs-status-badges"></div>
            <div id="bs-deep-status"></div>
        </div>
        <div class="bs-table-scroll">
            <table class="bs-table" id="bs-results-table">
                <thead>
                    <tr>
                        <th style="width:180px;"><?= htmlspecialchars($context['pk_label'], ENT_QUOTES, 'UTF-8') ?></th>
                        <?php if ($hasCustomLabel): ?>
                            <th>Record Label</th>
                        <?php endif; ?>
                        <?php if ($hasSecondaryPk): ?>
                            <th><?= htmlspecialchars($context['secondary_pk_label'], ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endif; ?>
                        <th>Matched Field(s) &amp; Value</th>
                    </tr>
                </thead>
                <tbody id="bs-results-tbody"></tbody>
                <tfoot>
                    <tr>
                        <td colspan="<?= 2 + ($hasCustomLabel ? 1 : 0) + ($hasSecondaryPk ? 1 : 0) ?>" class="bs-tfoot-cell">
                            <div class="bs-tfoot-bar">
                                <div id="bs-page-info" class="bs-page-info">Showing 0 results</div>
                                <div id="bs-page-controls" class="bs-page-controls"></div>
                                <div class="bs-page-size-wrap">
                                    <label for="bs-page-size-input">Results per page:</label>
                                    <input
                                        type="number"
                                        id="bs-page-size-input"
                                        class="bs-page-size-input"
                                        value="<?= (int) $savedPageSize ?>"
                                        min="1"
                                        max="500"
                                        step="1">
                                </div>
                            </div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php
$jsFieldMeta = [];
foreach ($fieldConfig['field_meta'] as $fn => $fm)
    $jsFieldMeta[(string) $fn] = [
        $module->escape((string) $fm['label']),
        $module->escape((string) $fm['form_name']),
        $module->escape((string) $fm['form_label']),
    ];

$jsEvents = [];
if ($context['longitudinal'])
    foreach ($context['events'] as $eId => $eInfo)
        $jsEvents[(string) $eId] = $module->escape((string) $eInfo['name']);
?>
<script>
    window.BetterSearchConfig = {
        module: <?= $jsObjectName ?>,
        projectId: <?= (int) $project_id ?>,
        userId: <?= json_encode(defined('USERID') ? (string) USERID : 'anon') ?>,
        webroot: <?= json_encode(APP_PATH_WEBROOT) ?>,
        hasCustomLabel: <?= $hasCustomLabel ? 'true' : 'false' ?>,
        hasSecondaryPk: <?= $hasSecondaryPk ? 'true' : 'false' ?>,
        selectedArm: <?= (int) $selectedArm ?>,
        singleForm: <?= json_encode((string) ($context['single_form'] ?? '')) ?>,
        multipleArms: <?= $context['multiple_arms'] ? 'true' : 'false' ?>,
        userDagId: <?= (int) ($context['user_dag_id'] ?? 0) ?>,
        autoIncSet: <?= $context['auto_inc_set'] ? 'true' : 'false' ?>,
        seedLocators: <?= json_encode($seedLocators) ?>,
        pkField: <?= json_encode((string) $context['pk']) ?>,
        pkLabel: <?= json_encode((string) $context['pk_label']) ?>,
        uniqueFields: <?= json_encode($uniqueFields) ?>,
        fieldMeta: <?= json_encode($module->escape($jsFieldMeta)) ?>,
        events: <?= json_encode($module->escape($jsEvents)) ?>
    };
</script>
<script src="<?= htmlspecialchars($module->getUrl('index.js'), ENT_QUOTES, 'UTF-8') ?>"></script>