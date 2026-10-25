<?php

namespace UWMadison\BetterSearch;

use DataEntry;
use ExternalModules\AbstractExternalModule;
use Piping;
use Project;
use REDCap;
use Records;
use UserRights;

class BetterSearch extends AbstractExternalModule
{
    /**
     * Allow project users to access the Better Search page, while hiding the External Modules
     * sidebar link when "Replace Native Add/Edit Records Page" is enabled.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        if ($this->getProjectSetting('replace_native_page', $project_id))
            $link['name'] = '';

        return $link;
    }

    /**
     * Hook executed at the top of every REDCap page.
     * Handles optional redirection from the native Add/Edit Records landing page,
     * updates sidebar links, and displays a subtle notice if a new record has a staged DAG.
     */
    public function redcap_every_page_top($project_id)
    {
        if (empty($project_id))
            return;

        $this->renderPendingDagNoticeIfApplicable($project_id);

        if (!$this->getProjectSetting('replace_native_page', $project_id))
            return;

        $searchUrl = $this->getUrl('index.php');

        $isNativeLanding = defined('PAGE')
            && PAGE === 'DataEntry/record_home.php'
            && $_SERVER['REQUEST_METHOD'] === 'GET'
            && !isset($_GET['id'])
            && !isset($_GET['auto'])
            && !isset($_GET['native']);

        if ($isNativeLanding) {
            $redirectUrl = $searchUrl;
            if (!empty($_GET['arm']) && is_numeric($_GET['arm']))
                $redirectUrl .= '&arm=' . (int) $_GET['arm'];
            $msg = $_GET['msg'] ?? '';
            if (in_array($msg, ['delete', 'record_deleted', 'rename', 'add', 'cancel'], true))
                $redirectUrl .= '&msg=' . $msg;
            header('Location: ' . $redirectUrl);
            $this->exitAfterHook();
            return;
        }

        $jsSearchUrl = json_encode($searchUrl);
?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const targetUrl = <?= $jsSearchUrl ?>;
                const recordHomeLinks = document.querySelectorAll('a[href*="DataEntry/record_home.php"]');
                recordHomeLinks.forEach((link) => {
                    const href = link.getAttribute('href') || '';
                    if (href.indexOf('&id=') === -1 && href.indexOf('?id=') === -1 && href.indexOf('native=1') === -1)
                        link.setAttribute('href', targetUrl);
                });
            });
        </script>
    <?php
    }

    /**
     * Hook executed whenever a record is saved.
     * Checks the hidden 'pending_dag_assignments' setting and assigns the staged DAG.
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        if (empty($project_id) || $record === '' || $record === null)
            return;

        $pending = $this->getPendingDagMap($project_id);
        if (empty($pending['records']) && empty($pending['users']))
            return;

        $recKey = (string) $record;
        $userKey = defined('USERID') ? strtolower((string) USERID) : '';
        $targetDagId = null;
        $dirty = false;

        if (isset($pending['records'][$recKey])) {
            $targetDagId = (int) ($pending['records'][$recKey]['group_id'] ?? 0);
            unset($pending['records'][$recKey]);
            if ($userKey !== '' && isset($pending['users'][$userKey]) && (string) ($pending['users'][$userKey]['record'] ?? '') === $recKey)
                unset($pending['users'][$userKey]);
            $dirty = true;
        } elseif ($userKey !== '' && isset($pending['users'][$userKey])) {
            $userEntry = $pending['users'][$userKey];
            if (!empty($userEntry['auto']) && (time() - (int) ($userEntry['ts'] ?? 0)) < 7200) {
                $targetDagId = (int) ($userEntry['group_id'] ?? 0);
                $oldRec = (string) ($userEntry['record'] ?? '');
                unset($pending['users'][$userKey], $pending['records'][$oldRec]);
                $dirty = true;
            }
        }

        if ($dirty)
            $this->savePendingDagMap($project_id, $pending);

        if (empty($targetDagId))
            return;

        $context = $this->getProjectContext($project_id);
        if (!isset($context['dags'][$targetDagId]))
            return;

        $this->assignRecordToDag($project_id, $recKey, $targetDagId, $event_id);
    }

    /**
     * Assign a record to a Data Access Group in both redcap_data and redcap_record_list.
     */
    protected function assignRecordToDag($project_id, $record, $dagId, $event_id = null)
    {
        $this->setDAG($record, (int) $dagId);

        $q = $this->createQuery();
        $q->add("UPDATE redcap_record_list SET dag_id = ? WHERE project_id = ? AND record = ?", [
            (int) $dagId,
            (int) $project_id,
            (string) $record,
        ]);
        $q->execute();

        REDCap::logEvent(
            'Better Search: Assigned record to Data Access Group',
            "group_id = $dagId",
            '',
            $record,
            $event_id,
            $project_id
        );
    }

    /**
     * Show a subtle banner on record_home.php / DataEntry/index.php when creating a record with a staged DAG.
     */
    protected function renderPendingDagNoticeIfApplicable($project_id)
    {
        if (!defined('PAGE') || !in_array(PAGE, ['DataEntry/record_home.php', 'DataEntry/index.php'], true))
            return;
        if (empty($_GET['id']))
            return;

        $recKey = (string) $_GET['id'];
        $pending = $this->getPendingDagMap($project_id);
        if (empty($pending['records'][$recKey]['group_id']))
            return;

        $dagId = (int) $pending['records'][$recKey]['group_id'];
        $context = $this->getProjectContext($project_id);
        if (!isset($context['dags'][$dagId]))
            return;

        $dataTable = $this->getDataTable($project_id);
        $q = $this->createQuery();
        $q->add("SELECT 1 FROM $dataTable WHERE project_id = ? AND record = ? AND field_name = '__GROUPID__' LIMIT 1", [
            $project_id,
            $recKey,
        ]);
        if ($q->execute()->fetch_assoc())
            return;

        $dagName = htmlspecialchars($context['dags'][$dagId], ENT_QUOTES, 'UTF-8');
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const target = document.getElementById('subheader') || document.querySelector('.projhdr');
                if (!target)
                    return;
                const banner = document.createElement('div');
                banner.className = 'alert alert-info py-1 px-3 my-2';
                banner.style.fontSize = '12.5px';
                banner.style.maxWidth = '850px';
                banner.innerHTML = '<i class="fas fa-users mr-1"></i> <strong>Data Access Group staged:</strong> ' +
                    'This record will be assigned to <strong><?= $dagName ?></strong> when saved.';
                target.parentNode.insertBefore(banner, target.nextSibling);
            });
        </script>
<?php
    }

    /**
     * Handle authenticated AJAX requests from the Better Search frontend.
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        if (empty($project_id))
            return ['error' => 'Missing project ID.'];

        $payload = is_array($payload) ? $payload : [];

        if ($action === 'search_fast')
            return $this->handleSearch($project_id, $payload, 'fast');
        if ($action === 'search_deep')
            return $this->handleSearch($project_id, $payload, 'deep');
        if ($action === 'stage_dag_assignment')
            return $this->handleStageDagAssignment($project_id, $payload);
        if ($action === 'fetch_locators')
            return $this->handleFetchLocators($project_id, $payload);

        return ['error' => 'Unsupported action.'];
    }

    /**
     * Stage (or clear) a pending DAG assignment in the hidden setting before redirecting to create/open a record.
     */
    protected function handleStageDagAssignment($project_id, array $payload)
    {
        $context = $this->getProjectContext($project_id, true);
        $canCreate = !isset($context['user_rights']['record_create']) || (string) $context['user_rights']['record_create'] !== '0';

        $isAuto = !empty($payload['auto']) && $context['auto_inc_set'];
        $recordId = $isAuto
            ? $this->getNextAutoRecordId($project_id)
            : trim((string) ($payload['record'] ?? ''));

        if ($recordId === '')
            return ['error' => 'Please enter a valid ' . $context['pk_label'] . '.'];

        if ($context['status'] === 0 && $context['dev_record_limit'] > 0 && !(defined('SUPER_USER') && SUPER_USER)) {
            $counts = $this->getRecordCounts($project_id, $context);
            if ($counts['total'] >= $context['dev_record_limit'] && $isAuto)
                return ['error' => 'This project has reached the maximum number of records allowed in Development status (' . $context['dev_record_limit'] . ').'];
        }

        $selectedArm = !empty($payload['arm']) && isset($context['arms'][(int) $payload['arm']])
            ? (int) $payload['arm']
            : 1;

        $dagId = !empty($payload['group_id']) ? (int) $payload['group_id'] : 0;
        $userKey = defined('USERID') ? strtolower((string) USERID) : '';

        if (empty($context['user_dag_id']) && !empty($context['dags']) && $canCreate) {
            $pending = $this->getPendingDagMap($project_id);
            if ($dagId > 0 && isset($context['dags'][$dagId])) {
                $entry = [
                    'record' => $recordId,
                    'group_id' => $dagId,
                    'user' => $userKey,
                    'auto' => $isAuto ? 1 : 0,
                    'ts' => time(),
                ];
                $pending['records'][$recordId] = $entry;
                if ($userKey !== '')
                    $pending['users'][$userKey] = $entry;
            } else {
                unset($pending['records'][$recordId]);
                if ($userKey !== '')
                    unset($pending['users'][$userKey]);
            }
            $this->savePendingDagMap($project_id, $pending);
        }

        if ($context['single_form']) {
            $redirectUrl = APP_PATH_WEBROOT . "DataEntry/index.php?pid={$project_id}&id=" . urlencode($recordId)
                . ($isAuto ? "&auto=1" : "")
                . "&page=" . urlencode($context['single_form']);
        } else {
            $redirectUrl = APP_PATH_WEBROOT . "DataEntry/record_home.php?pid={$project_id}&id=" . urlencode($recordId)
                . ($isAuto ? "&auto=1" : "")
                . ($context['multiple_arms'] ? "&arm={$selectedArm}" : "");
        }

        return [
            'record' => $recordId,
            'redirect_url' => $redirectUrl,
        ];
    }

    /**
     * Read and prune the hidden 'pending_dag_assignments' setting.
     */
    protected function getPendingDagMap($project_id)
    {
        $raw = $this->getProjectSetting('pending_dag_assignments', $project_id);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded))
            return ['records' => [], 'users' => []];

        $now = time();
        $records = is_array($decoded['records'] ?? null) ? $decoded['records'] : [];
        $users = is_array($decoded['users'] ?? null) ? $decoded['users'] : [];

        foreach ($records as $k => $v)
            if (($now - (int) ($v['ts'] ?? 0)) > 86400)
                unset($records[$k]);

        foreach ($users as $k => $v)
            if (($now - (int) ($v['ts'] ?? 0)) > 86400)
                unset($users[$k]);

        return ['records' => $records, 'users' => $users];
    }

    /**
     * Persist the hidden 'pending_dag_assignments' setting.
     */
    protected function savePendingDagMap($project_id, array $map)
    {
        $this->setProjectSetting('pending_dag_assignments', json_encode($map), $project_id);
    }

    /**
     * Gather project metadata, user rights, arms, events, and DAGs.
     */
    public function getProjectContext($project_id, bool $includeDevConfig = false)
    {
        global $Proj, $lang, $status_dev_mes;
        $project = ($Proj && (int) $Proj->project_id === (int) $project_id) ? $Proj : new Project($project_id);

        $userRights = $this->getUserRights($project_id);
        $formPerms = $userRights['forms'] ?? [];

        $status = (int) ($project->project['status'] ?? 0);
        $devMessage = (string) ($status_dev_mes ?? '');
        $devRecordLimit = 50;

        if ($includeDevConfig && $status === 0) {
            $resCfg = $this->query(
                "SELECT field_name, value FROM redcap_config WHERE field_name LIKE '%dev%' OR field_name LIKE '%record%'",
                []
            );
            while ($cfgRow = $resCfg->fetch_assoc()) {
                $fName = (string) $cfgRow['field_name'];
                $fVal = (string) $cfgRow['value'];
                if ($fName === 'status_dev_mes' && $devMessage === '')
                    $devMessage = (string) $this->escape($fVal);
                elseif (preg_match('/(?=.*dev)(?=.*record)/i', $fName) && is_numeric($fVal))
                    $devRecordLimit = (int) $fVal;
            }
        }

        $pk = $project->table_pk;
        $pkLabel = $this->cleanLabel($project->metadata[$pk]['element_label'] ?? 'Record ID');

        $secondaryPk = $project->project['secondary_pk'] ?? '';
        if (!empty($secondaryPk) && isset($project->metadata[$secondaryPk])) {
            $secForm = $project->metadata[$secondaryPk]['form_name'] ?? '';
            if (isset($formPerms[$secForm]) && (string) $formPerms[$secForm] === '0')
                $secondaryPk = '';
        } else {
            $secondaryPk = '';
        }
        $secondaryPkLabel = !empty($secondaryPk)
            ? $this->cleanLabel($project->metadata[$secondaryPk]['element_label'] ?? $secondaryPk)
            : '';

        $customRecordLabel = trim((string) ($project->project['custom_record_label'] ?? ''));
        $autoIncSet = !empty($project->project['auto_inc_set']);
        $longitudinal = (bool) $project->longitudinal;
        $multipleArms = (bool) $project->multiple_arms;
        $forms = $project->forms ?? [];
        $firstForm = array_key_first($forms);
        $singleForm = (!$longitudinal && count($forms) === 1) ? $firstForm : null;

        $arms = [];
        foreach ($project->events ?? [] as $armNum => $armData) {
            $armEvents = !empty($armData['events']) ? array_keys($armData['events']) : [];
            $firstEventId = !empty($armEvents) ? reset($armEvents) : $project->firstEventId;
            $arms[(int) $armNum] = [
                'arm_num' => (int) $armNum,
                'name' => $armData['name'] ?? ("Arm $armNum"),
                'first_event_id' => (int) $firstEventId,
                'event_ids' => array_map('intval', $armEvents),
            ];
        }

        $events = [];
        foreach ($project->eventInfo ?? [] as $evId => $evInfo)
            $events[(int) $evId] = [
                'name' => $evInfo['name_ext'] ?? $evInfo['name'] ?? ("Event $evId"),
                'arm_num' => (int) ($evInfo['arm_num'] ?? 1),
            ];

        $dags = [];
        $rawGroups = $project->getGroups();
        if (is_array($rawGroups))
            foreach ($rawGroups as $gid => $gname)
                $dags[(int) $gid] = $this->cleanLabel($gname);

        return [
            'project' => $project,
            'project_id' => (int) $project_id,
            'status' => $status,
            'status_dev_mes' => $devMessage,
            'dev_record_limit' => $devRecordLimit,
            'note_prefix' => ($lang['global_03'] ?? 'NOTE') . ($lang['colon'] ?? ':'),
            'pk' => $pk,
            'pk_label' => $pkLabel,
            'secondary_pk' => $secondaryPk,
            'secondary_pk_label' => $secondaryPkLabel,
            'custom_record_label' => $customRecordLabel,
            'auto_inc_set' => $autoIncSet,
            'longitudinal' => $longitudinal,
            'multiple_arms' => $multipleArms,
            'arms' => $arms,
            'events' => $events,
            'first_event_id' => (int) $project->firstEventId,
            'first_form' => $firstForm,
            'single_form' => $singleForm,
            'user_rights' => $userRights,
            'user_dag_id' => !empty($userRights['group_id']) ? (int) $userRights['group_id'] : null,
            'dags' => $dags,
        ];
    }

    /**
     * Retrieve current user's REDCap project rights safely.
     */
    protected function getUserRights($project_id)
    {
        global $user_rights;
        if (is_array($user_rights) && !empty($user_rights))
            return $user_rights;
        if (!defined('USERID'))
            return [];

        $rights = UserRights::getPrivileges($project_id, USERID);
        return $rights[$project_id][strtolower(USERID)] ?? $rights[$project_id][USERID] ?? [];
    }

    /**
     * Discover base fast search priority fields (PK, Secondary PK, Custom Label fields, Identifiers, Configured fields)
     * and validation-typed field buckets (Date, Phone, Email) while enforcing form-level access rights.
     */
    public function getFieldConfiguration($project_id, ?array $context = null, bool $includeEnums = true, string $enumFilterQuery = '')
    {
        $context = $context ?? $this->getProjectContext($project_id);
        /** @var Project $project */
        $project = $context['project'];
        $formPerms = $context['user_rights']['forms'] ?? [];

        $pk = $context['pk'];
        $secondaryPk = $context['secondary_pk'];
        $customLabel = $context['custom_record_label'];

        $customLabelFields = !empty($customLabel)
            ? array_keys(getBracketedFields($customLabel, true, true, true))
            : [];

        $needsComplexPiping = false;
        if (!empty($customLabel)) {
            $rawBrackets = array_keys(getBracketedFields($customLabel, true, false, false));
            foreach ($rawBrackets as $bKey) {
                if (strpos($bKey, '][') !== false || strpos($bKey, '-') !== false || strpos($bKey, ':') !== false) {
                    $needsComplexPiping = true;
                    break;
                }
            }
        }

        $configuredFields = $this->getProjectSetting('field', $project_id);
        if (!is_array($configuredFields))
            $configuredFields = !empty($configuredFields) ? [$configuredFields] : [];
        $configuredFields = array_values(array_filter($configuredFields));

        $configuredUnique = $this->getProjectSetting('additional_unique_fields', $project_id);
        if (!is_array($configuredUnique))
            $configuredUnique = !empty($configuredUnique) ? [$configuredUnique] : [];
        $configuredUnique = array_values(array_filter($configuredUnique));

        $uniqueFields = [$pk];
        if (!empty($secondaryPk))
            $uniqueFields[] = $secondaryPk;
        foreach ($configuredUnique as $uf)
            $uniqueFields[] = $uf;
        $uniqueFields = array_values(array_unique($uniqueFields));

        $baseTier1Fields = [$pk => 'Primary Key'];
        $allSearchableFields = [];
        $dateFields = [];
        $phoneFields = [];
        $emailFields = [];
        $enumFields = [];
        $fieldMeta = [];

        foreach ($project->metadata as $fieldName => $meta) {
            $elementType = $meta['element_type'] ?? '';
            if (in_array($elementType, ['descriptive', 'file'], true))
                continue;

            $formName = $meta['form_name'] ?? '';
            if ($fieldName !== $pk && isset($formPerms[$formName]) && (string) $formPerms[$formName] === '0')
                continue;

            $allSearchableFields[] = $fieldName;

            $valType = $meta['element_validation_type'] ?? '';
            $isPhi = !empty($meta['field_phi']) && (string) $meta['field_phi'] === '1';
            $label = $this->cleanLabel($meta['element_label'] ?? $fieldName);
            $formLabel = $this->cleanLabel($project->forms[$formName]['menu'] ?? $formName);
            $rawEnum = (string) ($meta['element_enum'] ?? '');

            $choices = [];
            if ($elementType === 'yesno' || $elementType === 'truefalse' || $elementType === 'sql')
                $choices = $this->extractFieldChoices($elementType, $rawEnum, $project_id, $fieldName);
            elseif ($includeEnums && $rawEnum !== '' && ($enumFilterQuery === '' || stripos($rawEnum, $enumFilterQuery) !== false))
                $choices = $this->extractFieldChoices($elementType, $rawEnum, $project_id, $fieldName);

            if (!empty($choices))
                $enumFields[$fieldName] = $choices;

            $fieldMeta[$fieldName] = [
                'name' => $fieldName,
                'label' => $label,
                'form_name' => $formName,
                'form_label' => $formLabel,
                'element_type' => $elementType,
                'validation_type' => $valType,
                'is_phi' => $isPhi,
                'raw_enum' => $rawEnum,
                'choices' => $choices,
            ];

            if (strpos($valType, 'date') === 0)
                $dateFields[] = $fieldName;
            if ($valType === 'phone')
                $phoneFields[] = $fieldName;
            if ($valType === 'email')
                $emailFields[] = $fieldName;

            $reason = $this->resolveBasePriorityReason($fieldName, $pk, $secondaryPk, $configuredFields, $configuredUnique, $customLabelFields, $isPhi);
            if ($reason !== null)
                $baseTier1Fields[$fieldName] = $reason;
        }

        return [
            'base_tier1_fields' => $baseTier1Fields,
            'unique_fields' => $uniqueFields,
            'all_searchable_fields' => $allSearchableFields,
            'custom_label_fields' => $customLabelFields,
            'needs_complex_piping' => $needsComplexPiping,
            'date_fields' => $dateFields,
            'phone_fields' => $phoneFields,
            'email_fields' => $emailFields,
            'enum_fields' => $enumFields,
            'field_meta' => $fieldMeta,
        ];
    }

    /**
     * Extract multiple-choice options for a field, including session-cached dynamic SQL fields.
     */
    protected function extractFieldChoices($elementType, $rawEnum, $project_id = null, $fieldName = '')
    {
        if (!in_array($elementType, ['select', 'radio', 'checkbox', 'yesno', 'truefalse', 'sql'], true))
            return [];
        if ($elementType === 'yesno')
            return ['1' => 'Yes', '0' => 'No'];
        if ($elementType === 'truefalse')
            return ['1' => 'True', '0' => 'False'];
        if ($elementType === 'sql')
            return $this->extractSqlFieldChoices($project_id, $fieldName, (string) $rawEnum);
        if (!empty($rawEnum))
            return parseEnum($rawEnum);
        return [];
    }

    /**
     * Evaluate a dynamic SQL field's choices once per 10-minute window in $_SESSION so searches stay fast.
     */
    protected function extractSqlFieldChoices($project_id, $fieldName, string $rawSql)
    {
        $rawSql = trim($rawSql);
        if ($rawSql === '' || empty($project_id) || $fieldName === '')
            return [];

        $sqlHash = md5($rawSql);
        $now = time();
        $cached = $_SESSION['better_search_sql_cache'][$project_id][$fieldName] ?? null;

        if (
            is_array($cached)
            && ($cached['hash'] ?? '') === $sqlHash
            && ($now - (int) ($cached['ts'] ?? 0)) < 600
            && is_array($cached['choices'] ?? null)
        )
            return $cached['choices'];

        $enumStr = getSqlFieldEnum($rawSql, $project_id, null, $fieldName);
        $choices = !empty($enumStr) ? parseEnum($enumStr) : [];

        if (count($choices) > 1000)
            $choices = array_slice($choices, 0, 1000, true);

        $_SESSION['better_search_sql_cache'][$project_id][$fieldName] = [
            'hash' => $sqlHash,
            'ts' => $now,
            'choices' => $choices,
        ];

        return $choices;
    }

    /**
     * Determine why a field belongs in the base Tier 1 set (independent of query type).
     */
    protected function resolveBasePriorityReason($fieldName, $pk, $secondaryPk, array $configuredFields, array $configuredUnique, array $customLabelFields, $isPhi)
    {
        if ($fieldName === $pk)
            return 'Primary Key';
        if ($fieldName === $secondaryPk && !empty($secondaryPk))
            return 'Secondary Unique Field';
        if (in_array($fieldName, $configuredUnique, true))
            return 'Unique Identifier';
        if (in_array($fieldName, $configuredFields, true))
            return 'Configured Priority';
        if (in_array($fieldName, $customLabelFields, true))
            return 'Custom Record Label';
        if ($isPhi)
            return 'Identifier';
        return null;
    }

    /**
     * Build the active Tier 1 and Tier 2 field lists for a specific parsed query,
     * dynamically promoting validated Date, Phone, or Email fields into Tier 1 when detected.
     */
    protected function resolveQueryFieldSets(array $fieldConfig, array $parsed)
    {
        $tier1 = $fieldConfig['base_tier1_fields'];

        if (!empty($parsed['date_variants']))
            foreach ($fieldConfig['date_fields'] as $df)
                if (!isset($tier1[$df]))
                    $tier1[$df] = 'Date Field';

        if (!empty($parsed['phone_variants']))
            foreach ($fieldConfig['phone_fields'] as $pf)
                if (!isset($tier1[$pf]))
                    $tier1[$pf] = 'Phone Field';

        if (!empty($parsed['is_email']))
            foreach ($fieldConfig['email_fields'] as $ef)
                if (!isset($tier1[$ef]))
                    $tier1[$ef] = 'Email Field';

        $tier1Names = array_keys($tier1);
        $tier2Names = array_values(array_diff($fieldConfig['all_searchable_fields'], $tier1Names));

        $tier1EnumMatches = [];
        $tier2EnumMatches = [];
        foreach ($parsed['enum_matches'] as $em) {
            if (isset($tier1[$em['field_name']]))
                $tier1EnumMatches[] = $em;
            else
                $tier2EnumMatches[] = $em;
        }

        return [
            'tier1_map' => $tier1,
            'tier1_fields' => $tier1Names,
            'tier2_fields' => $tier2Names,
            'tier1_enum_matches' => $tier1EnumMatches,
            'tier2_enum_matches' => $tier2EnumMatches,
        ];
    }

    /**
     * Smart Query Parser: analyzes user input to detect dates, phone numbers, emails,
     * multi-word tokens, and multiple-choice labels.
     */
    public function parseSearchQuery($rawQuery, array $fieldConfig)
    {
        $query = trim((string) $rawQuery);
        $detectedBadges = [];
        $dateVariants = [];
        $phoneVariants = [];
        $isEmail = false;

        $isoDate = $this->tryParseDateToken($query);
        if ($isoDate !== null) {
            $dateVariants[] = $isoDate;
            $detectedBadges[] = "Date ($isoDate)";
        } elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})$#', $query, $m)) {
            $month = (int) $m[1];
            $day = (int) $m[2];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $partialIso = '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                $dateVariants[] = $partialIso;
                $detectedBadges[] = "Month/Day ($partialIso)";
            }
        }

        $digitsOnly = preg_replace('/\D+/', '', $query);
        $looksLikePhone = empty($dateVariants)
            && preg_match('/^[\d\s\-\(\)\.\+]+$/', $query)
            && strlen($digitsOnly) >= 6
            && strlen($digitsOnly) <= 11;

        if ($looksLikePhone) {
            if (strlen($digitsOnly) === 11 && $digitsOnly[0] === '1')
                $digitsOnly = substr($digitsOnly, 1);

            $len = strlen($digitsOnly);
            if ($len === 10) {
                $area = substr($digitsOnly, 0, 3);
                $prefix = substr($digitsOnly, 3, 3);
                $line = substr($digitsOnly, 6, 4);
                $redcapPhone = "($area) $prefix-$line";
                $phoneVariants = array_unique([$redcapPhone, "$area-$prefix-$line", $digitsOnly, "$prefix-$line"]);
                $detectedBadges[] = "Phone ($redcapPhone)";
            } elseif ($len >= 8 && $len <= 9) {
                $area = substr($digitsOnly, 0, 3);
                $prefix = substr($digitsOnly, 3, 3);
                $line = substr($digitsOnly, 6);
                $redcapPhone = "($area) $prefix-$line";
                $phoneVariants = array_unique([$redcapPhone, "$area-$prefix-$line", "$area $prefix $line", "$prefix-$line", $digitsOnly]);
                $detectedBadges[] = "Phone ($redcapPhone)";
            } elseif ($len === 7) {
                $prefix = substr($digitsOnly, 0, 3);
                $line = substr($digitsOnly, 3, 4);
                $area7 = substr($digitsOnly, 0, 3);
                $prefix7 = substr($digitsOnly, 3, 3);
                $line1 = substr($digitsOnly, 6, 1);
                $phoneVariants = array_unique(["$prefix-$line", "($area7) $prefix7-$line1", "$area7-$prefix7-$line1", $digitsOnly]);
                $detectedBadges[] = "Phone ($prefix-$line)";
            } elseif ($len === 6) {
                $area = substr($digitsOnly, 0, 3);
                $prefix = substr($digitsOnly, 3, 3);
                $redcapPrefix = "($area) $prefix";
                $phoneVariants = array_unique([$redcapPrefix, "$area-$prefix", "$area $prefix", "($area)$prefix", $digitsOnly]);
                $detectedBadges[] = "Phone ($redcapPrefix)";
            }
        }

        if (strpos($query, '@') !== false || preg_match('/\.[a-z]{2,6}$/i', $query)) {
            $isEmail = true;
            $detectedBadges[] = 'Email';
        }

        $rawTokens = preg_split('/[\s,]+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($rawTokens as $t) {
            $t = trim($t);
            if ($t === '' || strlen($t) < 2)
                continue;

            $tokenIso = $this->tryParseDateToken($t);
            if ($tokenIso !== null) {
                $dateVariants[] = $tokenIso;
                $detectedBadges[] = "Date ($tokenIso)";
                $tokens[] = $tokenIso;
                continue;
            }
            $tokens[] = $t;
        }

        $dateVariants = array_values(array_unique($dateVariants));
        $detectedBadges = array_values(array_unique($detectedBadges));
        $tokens = array_values(array_unique($tokens));
        if (count($tokens) > 1 && empty($phoneVariants))
            $detectedBadges[] = 'Multi-field (' . implode(' + ', array_slice($tokens, 0, 4)) . ')';

        $enumMatches = $this->findEnumMatches($query, $fieldConfig);

        return [
            'raw' => $query,
            'detected_badges' => $detectedBadges,
            'date_variants' => $dateVariants,
            'phone_variants' => $phoneVariants,
            'is_email' => $isEmail,
            'tokens' => $tokens,
            'enum_matches' => $enumMatches,
        ];
    }

    /**
     * Parse a full date string (MM/DD/YYYY, MM-DD-YY, YYYY-MM-DD) into ISO YYYY-MM-DD, or null.
     */
    protected function tryParseDateToken($str)
    {
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $str, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = strlen($m[3]) === 2
                ? (((int) $m[3] > ((int) date('y') + 5)) ? ('19' . $m[3]) : ('20' . $m[3]))
                : $m[3];
            return checkdate((int) $month, (int) $day, (int) $year) ? "$year-$month-$day" : null;
        }

        if (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$#', $str, $m)) {
            $year = $m[1];
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            return checkdate((int) $month, (int) $day, (int) $year) ? "$year-$month-$day" : null;
        }

        return null;
    }

    /**
     * Match query against multiple-choice labels across enum fields, lazily parsing raw_enum only when matched.
     */
    protected function findEnumMatches($query, array $fieldConfig)
    {
        if (strlen($query) < 2)
            return [];

        $matches = [];
        foreach ($fieldConfig['field_meta'] as $fieldName => $meta) {
            $choices = $meta['choices'] ?? [];
            if (empty($choices)) {
                $rawEnum = $meta['raw_enum'] ?? '';
                if ($rawEnum === '' || stripos($rawEnum, $query) === false)
                    continue;
                if (!in_array($meta['element_type'] ?? '', ['select', 'radio', 'checkbox'], true))
                    continue;
                $choices = parseEnum($rawEnum);
            }

            foreach ($choices as $code => $label) {
                if (stripos((string) $label, $query) === false)
                    continue;
                $matches[] = ['field_name' => $fieldName, 'code' => (string) $code, 'label' => (string) $label];
                if (count($matches) >= 60)
                    return $matches;
            }
        }

        return $matches;
    }

    /**
     * Store compact search metadata in $_SESSION so subsequent AJAX searches avoid rebuilding project metadata.
     */
    public function primeSearchEnvironmentCache($project_id, array $context, array $fieldConfig): array
    {
        $userId = defined('USERID') ? (string) USERID : 'anon';
        $cacheKey = (int) $project_id . ':' . $userId;

        $cacheableContext = $context;
        unset($cacheableContext['project']);

        $_SESSION['better_search_env_cache'][$cacheKey] = [
            'ts' => time(),
            'context' => $cacheableContext,
            'field_config' => $fieldConfig,
        ];

        return [$cacheableContext, $fieldConfig];
    }

    /**
     * Read cached project context and field configuration from $_SESSION (5-minute TTL).
     */
    protected function getCachedSearchEnvironment($project_id): array
    {
        $userId = defined('USERID') ? (string) USERID : 'anon';
        $cacheKey = (int) $project_id . ':' . $userId;
        $now = time();

        $cached = $_SESSION['better_search_env_cache'][$cacheKey] ?? null;
        if (
            is_array($cached)
            && ($now - (int) ($cached['ts'] ?? 0)) < 300
            && isset($cached['context'], $cached['field_config']['needs_complex_piping'])
        )
            return [$cached['context'], $cached['field_config']];

        $context = $this->getProjectContext($project_id, false);
        $fieldConfig = $this->getFieldConfiguration($project_id, $context, false);
        return $this->primeSearchEnvironmentCache($project_id, $context, $fieldConfig);
    }

    /**
     * Execute Tier 1 (fast) or Tier 2 (deep) search and return hydrated record results.
     */
    protected function handleSearch($project_id, array $payload, $tier = 'fast')
    {
        $startTime = microtime(true);
        $rawQuery = trim((string) ($payload['query'] ?? ''));
        if ($rawQuery === '')
            return ['tier' => $tier, 'records' => [], 'elapsed_ms' => 0, 'detected_badges' => []];

        $excludeRecords = [];
        if (!empty($payload['exclude_records']) && is_array($payload['exclude_records']))
            foreach ($payload['exclude_records'] as $rec)
                $excludeRecords[(string) $rec] = true;

        $selectedArm = !empty($payload['arm']) ? (int) $payload['arm'] : null;
        $dagFilter = trim((string) ($payload['dag_filter'] ?? ''));
        $fuzzyThreshold = isset($payload['fuzzy_threshold']) ? (int) $payload['fuzzy_threshold'] : 100;
        if ($fuzzyThreshold < 50)
            $fuzzyThreshold = 50;
        if ($fuzzyThreshold > 100)
            $fuzzyThreshold = 100;

        $tMeta0 = microtime(true);
        [$context, $fieldConfig] = $this->getCachedSearchEnvironment($project_id);
        $dataTable = $this->getDataTable($project_id);

        $parsed = $this->parseSearchQuery($rawQuery, $fieldConfig);
        if ($fuzzyThreshold < 100)
            $parsed['detected_badges'][] = "Fuzzy ({$fuzzyThreshold}%)";
        $parsed['fuzzy_threshold'] = $fuzzyThreshold;

        $querySets = $this->resolveQueryFieldSets($fieldConfig, $parsed);
        $metaMs = round((microtime(true) - $tMeta0) * 1000, 1);

        $armEventIds = null;
        $armEventSet = null;
        if (!empty($context['multiple_arms']) && $selectedArm && !empty($context['arms'][$selectedArm]['event_ids'])) {
            $armEventIds = array_values(array_map('intval', $context['arms'][$selectedArm]['event_ids']));
            $armEventSet = array_fill_keys($armEventIds, true);
        }

        $tQuery0 = microtime(true);
        $dagAllowedRecords = null;
        if (!empty($context['user_dag_id']))
            $dagAllowedRecords = $this->getDagRecordSet($project_id, $context['user_dag_id'], $dataTable);
        elseif ($dagFilter !== '' && is_numeric($dagFilter) && isset($context['dags'][(int) $dagFilter]))
            $dagAllowedRecords = $this->getDagRecordSet($project_id, (int) $dagFilter, $dataTable);

        if (!empty($payload['include_records']) && is_array($payload['include_records'])) {
            $includeSet = [];
            foreach ($payload['include_records'] as $incRec)
                $includeSet[(string) $incRec] = true;
            $dagAllowedRecords = $dagAllowedRecords === null
                ? $includeSet
                : array_intersect_key($dagAllowedRecords, $includeSet);
        }

        $matchedHitsByRecord = [];

        if ($tier === 'fast')
            $this->runTier1Queries($project_id, $dataTable, $context, $querySets, $parsed, $dagAllowedRecords, $excludeRecords, $armEventIds, $armEventSet, $matchedHitsByRecord);
        else
            $this->runTier2Queries($project_id, $dataTable, $querySets, $parsed, $dagAllowedRecords, $excludeRecords, $armEventIds, $armEventSet, $matchedHitsByRecord);
        $queryMs = round((microtime(true) - $tQuery0) * 1000, 1);

        $tHydrate0 = microtime(true);
        $records = $this->hydrateMatchedRecords($project_id, $dataTable, $context, $fieldConfig, $querySets, $parsed, $matchedHitsByRecord, $selectedArm, $dagFilter);
        $hydrateMs = round((microtime(true) - $tHydrate0) * 1000, 1);

        return [
            'tier' => $tier,
            'records' => $records,
            'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 1),
            'timings' => [
                'meta_ms' => $metaMs,
                'query_ms' => $queryMs,
                'hydrate_ms' => $hydrateMs,
            ],
            'detected_badges' => $parsed['detected_badges'],
            'tier1_field_count' => count($querySets['tier1_fields']),
            'tier2_field_count' => count($querySets['tier2_fields']),
        ];
    }

    /**
     * Run Tier 1 (Fast) indexed queries against Primary Key, priority/identifier/type-matched fields,
     * and multi-token combinations.
     */
    protected function runTier1Queries(
        $project_id,
        $dataTable,
        array $context,
        array $querySets,
        array $parsed,
        $dagAllowedRecords,
        array $excludeRecords,
        ?array $armEventIds,
        ?array $armEventSet,
        array &$matchedHitsByRecord
    ) {
        $dataTable = $this->getDataTable($project_id);
        $raw = $parsed['raw'];
        $fuzzyThreshold = (int) ($parsed['fuzzy_threshold'] ?? 100);
        $tier1FieldNames = $querySets['tier1_fields'];
        if (empty($tier1FieldNames))
            return;

        $qFast = $this->createQuery();
        $qFast->add("SELECT record, event_id, field_name, value, instance FROM $dataTable");
        $qFast->add("WHERE project_id = ? AND", [$project_id]);
        if (!empty($armEventIds)) {
            $qFast->addInClause('event_id', $armEventIds);
            $qFast->add("AND");
        }
        $qFast->addInClause('field_name', $tier1FieldNames);

        [$orParts, $orParams] = $this->buildValueMatchClauses($raw, $parsed['date_variants'], $parsed['phone_variants'], $querySets['tier1_enum_matches'], $parsed['tokens'], $fuzzyThreshold);
        $limit = $fuzzyThreshold < 100 ? 1500 : 500;
        $qFast->add("AND (" . implode(" OR ", $orParts) . ") LIMIT $limit", $orParams);

        $resFast = $qFast->execute();
        while ($row = $resFast->fetch_assoc())
            $this->collectMatchHit($row, $dagAllowedRecords, $excludeRecords, $armEventSet, $parsed, $querySets['tier1_enum_matches'], $matchedHitsByRecord);

        $tokens = $parsed['tokens'];
        if (count($tokens) < 2 || count($tokens) > 5)
            return;

        $this->runMultiTokenQuery($project_id, $dataTable, $tier1FieldNames, $tokens, $dagAllowedRecords, $excludeRecords, $armEventIds, $armEventSet, $parsed, $matchedHitsByRecord);
    }

    /**
     * Find records where every search token matches at least one priority field.
     */
    protected function runMultiTokenQuery(
        $project_id,
        $dataTable,
        array $tier1FieldNames,
        array $tokens,
        $dagAllowedRecords,
        array $excludeRecords,
        ?array $armEventIds,
        ?array $armEventSet,
        array $parsed,
        array &$matchedHitsByRecord
    ) {
        $dataTable = $this->getDataTable($project_id);
        $selectSums = [];
        $sumParams = [];
        $orTokenParts = [];
        $orTokenParams = [];
        $havingParts = [];

        foreach ($tokens as $idx => $token) {
            $colAlias = "tok_$idx";
            $like = '%' . $token . '%';
            $selectSums[] = "MAX(CASE WHEN value LIKE ? OR record LIKE ? THEN 1 ELSE 0 END) AS $colAlias";
            $sumParams[] = $like;
            $sumParams[] = $like;

            $orTokenParts[] = "value LIKE ?";
            $orTokenParams[] = $like;
            $orTokenParts[] = "record LIKE ?";
            $orTokenParams[] = $like;

            $havingParts[] = "$colAlias = 1";
        }

        $qMulti = $this->createQuery();
        $qMulti->add("SELECT record, " . implode(", ", $selectSums) . " FROM $dataTable", $sumParams);
        $qMulti->add("WHERE project_id = ? AND", [$project_id]);
        if (!empty($armEventIds)) {
            $qMulti->addInClause('event_id', $armEventIds);
            $qMulti->add("AND");
        }
        $qMulti->addInClause('field_name', $tier1FieldNames);
        $qMulti->add("AND (" . implode(" OR ", $orTokenParts) . ")", $orTokenParams);
        $qMulti->add("GROUP BY record HAVING " . implode(" AND ", $havingParts) . " LIMIT 500");

        $resMulti = $qMulti->execute();
        $multiRecordIds = [];
        while ($mRow = $resMulti->fetch_assoc()) {
            $recId = (string) $mRow['record'];
            if ($dagAllowedRecords !== null && !isset($dagAllowedRecords[$recId]))
                continue;
            if (isset($excludeRecords[$recId]))
                continue;
            $multiRecordIds[] = $recId;
        }

        if (empty($multiRecordIds))
            return;

        $qMultiDetails = $this->createQuery();
        $qMultiDetails->add("SELECT record, event_id, field_name, value, instance FROM $dataTable");
        $qMultiDetails->add("WHERE project_id = ? AND", [$project_id]);
        if (!empty($armEventIds)) {
            $qMultiDetails->addInClause('event_id', $armEventIds);
            $qMultiDetails->add("AND");
        }
        $qMultiDetails->addInClause('record', $multiRecordIds);
        $qMultiDetails->add("AND");
        $qMultiDetails->addInClause('field_name', $tier1FieldNames);

        $detailOr = [];
        $detailParams = [];
        foreach ($tokens as $token) {
            $detailOr[] = "value LIKE ?";
            $detailParams[] = '%' . $token . '%';
        }
        $qMultiDetails->add("AND (" . implode(" OR ", $detailOr) . ")", $detailParams);
        $resDetails = $qMultiDetails->execute();
        while ($dRow = $resDetails->fetch_assoc())
            $this->collectMatchHit($dRow, $dagAllowedRecords, $excludeRecords, $armEventSet, $parsed, [], $matchedHitsByRecord);
    }

    /**
     * Run Tier 2 (Deep) query across all remaining non-priority fields in the project.
     */
    protected function runTier2Queries(
        $project_id,
        $dataTable,
        array $querySets,
        array $parsed,
        $dagAllowedRecords,
        array $excludeRecords,
        ?array $armEventIds,
        ?array $armEventSet,
        array &$matchedHitsByRecord
    ) {
        $dataTable = $this->getDataTable($project_id);
        $tier2Fields = $querySets['tier2_fields'];
        if (empty($tier2Fields))
            return;

        $fuzzyThreshold = (int) ($parsed['fuzzy_threshold'] ?? 100);

        $qDeep = $this->createQuery();
        $qDeep->add("SELECT record, event_id, field_name, value, instance FROM $dataTable");
        $qDeep->add("WHERE project_id = ? AND", [$project_id]);
        if (!empty($armEventIds)) {
            $qDeep->addInClause('event_id', $armEventIds);
            $qDeep->add("AND");
        }
        $qDeep->addInClause('field_name', $tier2Fields);

        [$orParts, $orParams] = $this->buildValueMatchClauses($parsed['raw'], $parsed['date_variants'], $parsed['phone_variants'], $querySets['tier2_enum_matches'], $parsed['tokens'], $fuzzyThreshold);
        $limit = $fuzzyThreshold < 100 ? 1500 : 500;
        $qDeep->add("AND (" . implode(" OR ", $orParts) . ") LIMIT $limit", $orParams);

        $resDeep = $qDeep->execute();
        while ($row = $resDeep->fetch_assoc())
            $this->collectMatchHit($row, $dagAllowedRecords, $excludeRecords, $armEventSet, $parsed, $querySets['tier2_enum_matches'], $matchedHitsByRecord);
    }

    /**
     * Build SQL OR clauses and bind parameters for raw values, dates, phones, enum codes, multi-token phrases, and fuzzy n-grams.
     */
    protected function buildValueMatchClauses($raw, array $dateVariants, array $phoneVariants, array $enumMatches, array $tokens = [], int $fuzzyThreshold = 100)
    {
        $orParts = ["value LIKE ?"];
        $orParams = ['%' . $raw . '%'];

        if (count($tokens) >= 2 && count($tokens) <= 6) {
            $andConditions = [];
            foreach ($tokens as $t) {
                $andConditions[] = "value LIKE ?";
                $orParams[] = '%' . $t . '%';
            }
            $orParts[] = "(" . implode(" AND ", $andConditions) . ")";

            if ($fuzzyThreshold < 100 && count($tokens) >= 3) {
                for ($skipIdx = 0; $skipIdx < count($tokens); $skipIdx++) {
                    $subset = [];
                    foreach ($tokens as $tIdx => $tVal) {
                        if ($tIdx === $skipIdx)
                            continue;
                        $subset[] = "value LIKE ?";
                        $orParams[] = '%' . $tVal . '%';
                    }
                    $orParts[] = "(" . implode(" AND ", $subset) . ")";
                }
            }
        }

        foreach ($dateVariants as $dv) {
            $orParts[] = "value LIKE ?";
            $orParams[] = '%' . $dv . '%';
        }

        foreach ($phoneVariants as $pv) {
            $orParts[] = "value LIKE ?";
            $orParams[] = '%' . $pv . '%';
        }

        foreach ($enumMatches as $em) {
            $orParts[] = "(field_name = ? AND value = ?)";
            $orParams[] = $em['field_name'];
            $orParams[] = $em['code'];
        }

        if ($fuzzyThreshold < 100 && (empty($tokens) || count($tokens) <= 2)) {
            $fuzzyPatterns = $this->buildFuzzySqlPatterns($raw);
            foreach ($fuzzyPatterns as $fp) {
                $orParts[] = "value LIKE ?";
                $orParams[] = $fp;
            }
        }

        return [$orParts, $orParams];
    }

    /**
     * Generate SQL LIKE patterns from 2-character bigrams so candidate rows with typos are retrieved by the index.
     */
    protected function buildFuzzySqlPatterns(string $raw): array
    {
        $words = preg_split('/[\s,]+/', strtolower(trim($raw)), -1, PREG_SPLIT_NO_EMPTY);
        $patterns = [];

        foreach ($words as $w) {
            $len = strlen($w);
            if ($len < 3)
                continue;

            $patterns[] = '%' . substr($w, 0, 2) . '%';
            $patterns[] = '%' . substr($w, -2) . '%';
            if ($len >= 4) {
                $mid = (int) floor(($len - 2) / 2);
                $patterns[] = '%' . substr($w, $mid, 2) . '%';
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Compute similarity percentage (0-100) between a field value and the parsed search query.
     */
    protected function computeFuzzySimilarity(string $value, array $parsed, string $fieldName = '', array $enumMatches = []): float
    {
        $valLower = strtolower(trim($value));
        $rawLower = strtolower(trim((string) ($parsed['raw'] ?? '')));
        if ($valLower === '' || $rawLower === '')
            return 0.0;

        if (strpos($valLower, $rawLower) !== false)
            return 100.0;

        foreach ($parsed['date_variants'] ?? [] as $dv)
            if (strpos($valLower, strtolower($dv)) !== false)
                return 100.0;

        foreach ($parsed['phone_variants'] ?? [] as $pv)
            if (strpos($valLower, strtolower($pv)) !== false)
                return 100.0;

        foreach ($enumMatches as $em)
            if ($em['field_name'] === $fieldName && (string) $em['code'] === (string) $value)
                return 100.0;

        $tokens = !empty($parsed['tokens']) ? $parsed['tokens'] : [$rawLower];
        $valWords = preg_split('/[\s,;:\-\/\(\)\.]+/', $valLower, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($valWords))
            $valWords = [$valLower];

        $tokenScores = [];
        foreach ($tokens as $tok) {
            $tokLower = strtolower($tok);
            if (strpos($valLower, $tokLower) !== false) {
                $tokenScores[] = 100.0;
                continue;
            }

            $bestForToken = $this->computeWordPairSimilarity($tokLower, $valLower);
            foreach ($valWords as $vw) {
                $score = $this->computeWordPairSimilarity($tokLower, $vw);
                if ($score > $bestForToken)
                    $bestForToken = $score;
            }
            $tokenScores[] = $bestForToken;
        }

        return empty($tokenScores) ? 0.0 : min($tokenScores);
    }

    /**
     * Compute similarity (0-100) between a query token and a candidate word, accounting for transpositions.
     */
    protected function computeWordPairSimilarity(string $queryWord, string $candidateWord): float
    {
        $qLen = strlen($queryWord);
        $cLen = strlen($candidateWord);
        if ($qLen === 0 || $cLen === 0 || $qLen > 64 || $cLen > 64)
            return 0.0;

        if (strpos($candidateWord, $queryWord) !== false)
            return 100.0;

        $maxLen = max($qLen, $cLen);
        if (abs($qLen - $cLen) > ceil($maxLen * 0.5))
            return 0.0;

        $dist = $this->damerauLevenshtein($queryWord, $candidateWord);
        return max(0.0, (1.0 - ($dist / $maxLen)) * 100.0);
    }

    /**
     * Optimal string alignment distance (Levenshtein with adjacent transposition cost = 1).
     */
    protected function damerauLevenshtein(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        $d = [];

        for ($i = 0; $i <= $lenA; $i++)
            $d[$i][0] = $i;
        for ($j = 0; $j <= $lenB; $j++)
            $d[0][$j] = $j;

        for ($i = 1; $i <= $lenA; $i++) {
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,
                    $d[$i][$j - 1] + 1,
                    $d[$i - 1][$j - 1] + $cost
                );
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1])
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }

        return $d[$lenA][$lenB];
    }

    /**
     * Filter and collect a matching EAV row into the per-record bucket (capped at 150 unique records).
     */
    protected function collectMatchHit(
        array $row,
        $dagAllowedRecords,
        array $excludeRecords,
        ?array $armEventSet,
        array $parsed,
        array $enumMatches,
        array &$matchedHitsByRecord
    ) {
        $recId = (string) ($row['record'] ?? '');
        if ($recId === '')
            return;
        if ($armEventSet !== null && !isset($armEventSet[(int) ($row['event_id'] ?? 0)]))
            return;
        if ($dagAllowedRecords !== null && !isset($dagAllowedRecords[$recId]))
            return;
        if (isset($excludeRecords[$recId]))
            return;
        if (!isset($matchedHitsByRecord[$recId]) && count($matchedHitsByRecord) >= 150)
            return;

        $val = (string) ($row['value'] ?? '');
        $fname = (string) ($row['field_name'] ?? '');
        $fuzzyThreshold = (int) ($parsed['fuzzy_threshold'] ?? 100);
        $similarity = 100.0;

        if ($fuzzyThreshold < 100) {
            $similarity = $this->computeFuzzySimilarity($val, $parsed, $fname, $enumMatches);
            if ($similarity < $fuzzyThreshold)
                return;
        }

        $matchedHitsByRecord[$recId] = $matchedHitsByRecord[$recId] ?? [];
        if (count($matchedHitsByRecord[$recId]) >= 8)
            return;

        $hitKey = $fname . '|' . ($row['event_id'] ?? '') . '|' . ($row['instance'] ?? '1') . '|' . $val;
        $matchedHitsByRecord[$recId][$hitKey] = [
            'field_name' => $fname,
            'event_id' => (int) ($row['event_id'] ?? 0),
            'instance' => (int) ($row['instance'] ?: 1),
            'value' => $val,
            'similarity' => $similarity,
        ];
    }

    /**
     * Hydrate matched records with Custom Record Label (if any), Secondary Unique Field (if any),
     * DAG name, Arm badges, relevance sorting, and direct navigation URLs.
     */
    protected function hydrateMatchedRecords(
        $project_id,
        $dataTable,
        array $context,
        array $fieldConfig,
        array $querySets,
        array $parsed,
        array $matchedHitsByRecord,
        $selectedArm = null,
        $dagFilter = ''
    ) {
        $dataTable = $this->getDataTable($project_id);
        if (empty($matchedHitsByRecord))
            return [];

        $recordIds = array_map('strval', array_keys($matchedHitsByRecord));
        $secondaryPk = $context['secondary_pk'];
        $allEventIds = !empty($context['events'])
            ? array_keys($context['events'])
            : [$context['first_event_id']];

        $hydrateFields = $fieldConfig['custom_label_fields'];
        if (!empty($secondaryPk))
            $hydrateFields[] = $secondaryPk;
        if (!empty($context['dags']))
            $hydrateFields[] = '__GROUPID__';
        if ($context['multiple_arms'] && empty($hydrateFields))
            $hydrateFields[] = $context['pk'];
        $hydrateFields = array_values(array_unique(array_filter($hydrateFields)));

        $customLabel = $context['custom_record_label'];
        $needsComplexPiping = !empty($fieldConfig['needs_complex_piping']);

        $recordData = [];
        $pipingRecordData = [];
        $recordDags = [];
        $recordArms = [];
        $recordAllFields = [];
        $lockedForms = [];

        $qLock = $this->createQuery();
        $qLock->add("SELECT record, event_id, form_name, instance FROM redcap_locking_data WHERE project_id = ? AND", [$project_id]);
        $qLock->addInClause('record', $recordIds);
        $resLock = $qLock->execute();
        while ($lRow = $resLock->fetch_assoc()) {
            $lRec = (string) $lRow['record'];
            $lEv = (int) ($lRow['event_id'] ?? 0);
            $lForm = (string) ($lRow['form_name'] ?? '');
            $lInst = (int) ($lRow['instance'] ?: 1);
            $lockedForms["$lRec|$lEv|$lForm|$lInst"] = true;
        }

        if ($needsComplexPiping && !empty($fieldConfig['custom_label_fields'])) {
            foreach ($recordIds as $rId) {
                $rKey = (string) $rId;
                foreach ($allEventIds as $eId) {
                    foreach ($fieldConfig['custom_label_fields'] as $cField) {
                        $cMeta = $fieldConfig['field_meta'][$cField] ?? null;
                        $cChoices = $cMeta['choices'] ?? [];
                        if (empty($cChoices) && !empty($cMeta['raw_enum']) && ($cMeta['element_type'] ?? '') === 'checkbox')
                            $cChoices = parseEnum($cMeta['raw_enum']);
                        if ($cMeta && $cMeta['element_type'] === 'checkbox' && !empty($cChoices)) {
                            foreach ($cChoices as $cCode => $cLabel)
                                $pipingRecordData[$rKey][$eId][$cField][$cCode] = '0';
                        } else {
                            $pipingRecordData[$rKey][$eId][$cField] = '';
                        }
                    }
                }
            }
        }

        if (!empty($hydrateFields)) {
            $qHydrate = $this->createQuery();
            $qHydrate->add("SELECT record, event_id, field_name, value, instance FROM $dataTable");
            $qHydrate->add("WHERE project_id = ? AND", [$project_id]);
            $qHydrate->addInClause('record', $recordIds);
            $qHydrate->add("AND");
            $qHydrate->addInClause('field_name', $hydrateFields);

            $resHydrate = $qHydrate->execute();
            while ($row = $resHydrate->fetch_assoc()) {
                $rec = (string) $row['record'];
                $fname = (string) $row['field_name'];
                $evId = (int) ($row['event_id'] ?? 0);
                $inst = (int) ($row['instance'] ?: 1);
                $val = (string) ($row['value'] ?? '');

                if ($context['multiple_arms'] && isset($context['events'][$evId])) {
                    $armNum = $context['events'][$evId]['arm_num'];
                    $recordArms[$rec][$armNum] = $context['arms'][$armNum]['name'] ?? "Arm $armNum";
                }

                if ($val === '')
                    continue;
                if ($fname === '__GROUPID__') {
                    $recordDags[$rec] = (int) $val;
                    continue;
                }

                $meta = $fieldConfig['field_meta'][$fname] ?? null;
                if (!$meta)
                    continue;

                if ($needsComplexPiping) {
                    if ($meta['element_type'] === 'checkbox')
                        $pipingRecordData[$rec][$evId][$fname][$val] = '1';
                    elseif (($pipingRecordData[$rec][$evId][$fname] ?? '') === '')
                        $pipingRecordData[$rec][$evId][$fname] = $val;
                }

                $formatted = $this->formatFieldValue($val, $meta);

                if (!isset($recordData[$rec][$fname]))
                    $recordData[$rec][$fname] = $formatted;
                elseif ($meta['element_type'] === 'checkbox' && strpos($recordData[$rec][$fname], $formatted) === false)
                    $recordData[$rec][$fname] .= ', ' . $formatted;

                $recordAllFields[$rec] = $recordAllFields[$rec] ?? [];
                if (count($recordAllFields[$rec]) < 60) {
                    $fForm = $meta['form_name'] ?? '';
                    $isLocked = !empty($lockedForms["$rec|$evId|$fForm|$inst"]) ? 1 : 0;
                    $recordAllFields[$rec][] = [$fname, $formatted, $val, $evId, $inst, $isLocked];
                }
            }
        }

        $rawLower = strtolower($parsed['raw']);
        $results = [];

        foreach ($matchedHitsByRecord as $recId => $hitsMap) {
            $dagId = $recordDags[$recId] ?? null;
            if ($dagFilter === 'none' && !empty($dagId))
                continue;

            $recLower = strtolower((string) $recId);
            $score = count($hitsMap) * 10;
            if ($recLower === $rawLower)
                $score += 1000;
            elseif (strpos($recLower, $rawLower) === 0)
                $score += 500;
            elseif (strpos($recLower, $rawLower) !== false)
                $score += 200;

            $formattedMatches = [];
            $primaryArm = ($selectedArm && isset($recordArms[$recId][$selectedArm]))
                ? $selectedArm
                : (!empty($recordArms[$recId]) ? (int) array_key_first($recordArms[$recId]) : ($selectedArm ?: 1));

            foreach ($hitsMap as $hit) {
                $fname = $hit['field_name'];
                $evId = $hit['event_id'];
                $inst = $hit['instance'];
                $hitSim = (float) ($hit['similarity'] ?? 100.0);
                if ($hitSim >= 100.0)
                    $score += 50;
                else
                    $score += (int) round($hitSim * 0.3);

                $meta = $fieldConfig['field_meta'][$fname] ?? null;

                $eventName = '';
                if ($context['longitudinal'] && isset($context['events'][$evId])) {
                    $eventName = $context['events'][$evId]['name'];
                    if (empty($recordArms[$recId]))
                        $primaryArm = $context['events'][$evId]['arm_num'] ?: $primaryArm;
                }

                $formName = $meta['form_name'] ?? $context['first_form'];
                $isFormLocked = !empty($lockedForms["$recId|$evId|$formName|$inst"]);
                $formUrl = APP_PATH_WEBROOT . "DataEntry/index.php?pid={$project_id}&id=" . urlencode((string) $recId)
                    . "&page=" . urlencode((string) $formName)
                    . ($evId ? "&event_id={$evId}" : "")
                    . ($inst > 1 ? "&instance={$inst}" : "")
                    . "&fldfocus=" . urlencode((string) $fname) . "#{$fname}-tr";

                $formattedMatches[] = [
                    'field_name' => $fname,
                    'field_label' => $meta['label'] ?? $fname,
                    'form_name' => $formName,
                    'form_label' => $meta['form_label'] ?? $formName,
                    'priority_reason' => $querySets['tier1_map'][$fname] ?? null,
                    'event_id' => $evId,
                    'event_name' => $eventName,
                    'instance' => $inst,
                    'value' => $this->formatFieldValue($hit['value'], $meta),
                    'raw_value' => $hit['value'],
                    'form_url' => $formUrl,
                    'locked' => $isFormLocked,
                ];
            }

            $customLabelRendered = $this->renderCustomRecordLabel(
                $customLabel,
                (string) $recId,
                $context['first_event_id'],
                $recordData,
                $pipingRecordData,
                $fieldConfig['custom_label_fields'],
                $needsComplexPiping,
                $project_id
            );
            $secondaryPkValue = (!empty($secondaryPk) && isset($recordData[$recId][$secondaryPk]))
                ? $recordData[$recId][$secondaryPk]
                : '';

            $recordUrl = $context['single_form']
                ? APP_PATH_WEBROOT . "DataEntry/index.php?pid={$project_id}&id=" . urlencode((string) $recId) . "&page=" . urlencode($context['single_form'])
                : APP_PATH_WEBROOT . "DataEntry/record_home.php?pid={$project_id}&id=" . urlencode((string) $recId) . ($context['multiple_arms'] ? "&arm={$primaryArm}" : "");

            $dagName = ($dagId && isset($context['dags'][$dagId])) ? $context['dags'][$dagId] : '';
            $armLabels = !empty($recordArms[$recId]) ? array_values($recordArms[$recId]) : [];
            $armNums = !empty($recordArms[$recId]) ? array_map('intval', array_keys($recordArms[$recId])) : [];

            $results[] = [
                'record' => (string) $recId,
                'custom_label' => $customLabelRendered,
                'secondary_pk_value' => $secondaryPkValue,
                'dag_name' => $dagName,
                'arms' => $armLabels,
                'arm_nums' => $armNums,
                'record_url' => $recordUrl,
                'matches' => $formattedMatches,
                'fields' => $recordAllFields[$recId] ?? $formattedMatches,
                '_score' => $score,
            ];
        }

        usort($results, fn($a, $b) => $a['_score'] !== $b['_score'] ? ($b['_score'] <=> $a['_score']) : strnatcasecmp($a['record'], $b['record']));

        foreach ($results as &$r)
            unset($r['_score']);

        return $results;
    }

    /**
     * Render piped values in a project's custom_record_label template.
     * Uses fast direct substitution for standard [field_name] labels (extracted via REDCap's getBracketedFields)
     * and falls back to Piping::replaceVariablesInLabel when smart variables or event prefixes are present.
     */
    protected function renderCustomRecordLabel(
        $template,
        $recId,
        $firstEventId,
        array $recordData,
        array $pipingRecordData,
        array $customLabelFields,
        bool $needsComplexPiping,
        $project_id
    ) {
        if (empty($template))
            return '';

        if ($needsComplexPiping) {
            $rendered = Piping::replaceVariablesInLabel(
                $template,
                $recId,
                $firstEventId,
                1,
                $pipingRecordData,
                false,
                $project_id,
                false
            );
        } else {
            $search = [];
            $replace = [];
            foreach ($customLabelFields as $fname) {
                $search[] = '[' . $fname . ']';
                $replace[] = $recordData[$recId][$fname] ?? '';
            }
            $rendered = str_ireplace($search, $replace, $template);
        }

        $rendered = trim(strip_tags((string) $rendered));
        return trim(preg_replace('/\(\s*\)|\[\s*\]|^[\s,\-:]+|[\s,\-:]+$/', '', $rendered));
    }

    /**
     * Format a raw EAV value using field metadata (resolving enum choice labels and formatting dates).
     */
    protected function formatFieldValue($rawVal, ?array $meta)
    {
        if ($rawVal === '' || $rawVal === null || !$meta)
            return (string) $rawVal;

        $choices = $meta['choices'] ?? [];
        if (empty($choices) && !empty($meta['raw_enum']) && in_array($meta['element_type'] ?? '', ['select', 'radio', 'checkbox'], true))
            $choices = parseEnum($meta['raw_enum']);

        if (!empty($choices) && isset($choices[(string) $rawVal]))
            return $choices[(string) $rawVal] . " ($rawVal)";

        $valType = $meta['validation_type'] ?? '';
        if (strpos($valType, 'date') === 0 && preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $rawVal, $m)) {
            if (strpos($valType, '_mdy') !== false)
                return "{$m[2]}/{$m[3]}/{$m[1]}" . ($m[4] ?: '');
            if (strpos($valType, '_dmy') !== false)
                return "{$m[3]}/{$m[2]}/{$m[1]}" . ($m[4] ?: '');
        }

        return (string) $rawVal;
    }

    /**
     * Return the set of record IDs belonging to a specific Data Access Group using redcap_record_list (with dataTable fallback).
     */
    protected function getDagRecordSet($project_id, $dagId, $dataTable)
    {
        $dataTable = $this->getDataTable($project_id);
        $allowed = [];
        $resList = $this->query(
            "SELECT record FROM redcap_record_list WHERE project_id = ? AND dag_id = ?",
            [$project_id, (int) $dagId]
        );
        while ($row = $resList->fetch_assoc())
            $allowed[(string) $row['record']] = true;

        if (!empty($allowed))
            return $allowed;

        $q = $this->createQuery();
        $q->add("SELECT DISTINCT record FROM $dataTable WHERE project_id = ? AND field_name = '__GROUPID__' AND value = ?", [
            $project_id,
            (string) $dagId,
        ]);
        $res = $q->execute();
        while ($row = $res->fetch_assoc())
            $allowed[(string) $row['record']] = true;

        return $allowed;
    }

    /**
     * Compute next candidate Record ID when auto-numbering is enabled.
     */
    public function getNextAutoRecordId($project_id)
    {
        return (string) DataEntry::getAutoId($project_id);
    }

    /**
     * Get total record count, DAG-scoped record count, and active Arm record count using redcap_record_list.
     */
    public function getRecordCounts($project_id, ?array $context = null, $selectedArm = null)
    {
        $context = $context ?? $this->getProjectContext($project_id);
        $totalCount = (int) Records::getRecordCount($project_id);

        $dagCount = null;
        if (!empty($context['user_dag_id'])) {
            $dagRow = $this->query(
                "SELECT COUNT(*) AS cnt FROM redcap_record_list WHERE project_id = ? AND dag_id = ?",
                [$project_id, (int) $context['user_dag_id']]
            )->fetch_assoc();
            $dagCount = (int) ($dagRow['cnt'] ?? 0);
        }

        $armCount = null;
        $byArm = [];
        if ($context['multiple_arms'] && !empty($context['arms'])) {
            foreach ($context['arms'] as $aNum => $aInfo)
                $byArm[(int) $aNum] = 0;

            $resArms = $this->query(
                "SELECT arm, COUNT(*) AS cnt FROM redcap_record_list WHERE project_id = ? GROUP BY arm",
                [$project_id]
            );
            while ($aRow = $resArms->fetch_assoc())
                $byArm[(int) $aRow['arm']] = (int) $aRow['cnt'];

            if ($selectedArm && isset($byArm[(int) $selectedArm]))
                $armCount = $byArm[(int) $selectedArm];
        }

        return [
            'total' => $totalCount,
            'dag' => $dagCount,
            'arm' => $armCount,
            'arms' => $byArm,
        ];
    }

    /**
     * Render native REDCap Development status and test record max notices when project status is Development (0).
     */
    public function renderDevelopmentNotices(array $context, array $counts)
    {
        if ((int) ($context['status'] ?? 1) !== 0)
            return;

        $limit = (int) ($context['dev_record_limit'] ?? 0);
        $total = (int) ($counts['total'] ?? 0);
        $isOverLimit = $limit > 0 && $total >= $limit;

        $boxClass = $isOverLimit ? 'red' : 'yellow';
        $icon = $isOverLimit ? 'exclamation.png' : 'exclamation_orange.png';

        $text = '<b>NOTICE:</b> This project is currently in Development status. '
            . '<b>Real data should NOT be entered</b> until the project has been moved to Production status.';

        if ($limit > 0)
            $text .= " You are currently using $total of $limit test records allowed while in Development status.";

        if (!empty($context['status_dev_mes']))
            $text .= '<br><br>' . $this->escape((string) $context['status_dev_mes']);

        echo '<div class="' . $boxClass . '" style="margin:10px 0 14px;max-width:960px;">'
            . '<img src="' . APP_PATH_IMAGES . $icon . '" alt="" style="vertical-align:middle;position:relative;top:-2px;margin-right:2px;"> '
            . $text
            . '</div>';
    }

    /**
     * Handle background chunked locator fetching.
     * Returns a flat KV mapping: { "locator_lowercase": [ "pk", "custom_label" ] }
     */
    public function handleFetchLocators($project_id, array $payload): array
    {
        $context = $this->getProjectContext($project_id, false);
        $fieldConfig = $this->getFieldConfiguration($project_id, $context, false);
        $userDagId = $context['user_dag_id'] ?? null;
        $pk = (string) ($context['pk'] ?? 'record_id');
        $autoIncSet = !empty($context['auto_inc_set']);
        $uniqueFields = $fieldConfig['unique_fields'] ?? [$pk];

        $limit = isset($payload['limit']) ? max(10, min(10000, (int) $payload['limit'])) : 5000;
        $offset = isset($payload['offset']) ? max(0, (int) $payload['offset']) : 0;
        $sinceMaxPk = isset($payload['since_max_pk']) && is_numeric($payload['since_max_pk']) ? (float) $payload['since_max_pk'] : null;

        $recordsInChunk = [];
        $hasMore = false;
        $maxPkInChunk = null;

        $hasRecordList = true;
        $qCheck = $this->query("SELECT 1 FROM redcap_record_list WHERE project_id = ? LIMIT 1", [$project_id]);
        if (!$qCheck->fetch_assoc())
            $hasRecordList = false;

        if ($hasRecordList) {
            $qList = $this->createQuery();
            if ($autoIncSet && $sinceMaxPk !== null) {
                if (!empty($userDagId)) {
                    $qList->add(
                        "SELECT DISTINCT record FROM redcap_record_list WHERE project_id = ? AND dag_id = ? AND (record + 0) > ? ORDER BY (record + 0) ASC LIMIT ?",
                        [$project_id, (int) $userDagId, $sinceMaxPk, $limit + 1]
                    );
                } else {
                    $qList->add(
                        "SELECT DISTINCT record FROM redcap_record_list WHERE project_id = ? AND (record + 0) > ? ORDER BY (record + 0) ASC LIMIT ?",
                        [$project_id, $sinceMaxPk, $limit + 1]
                    );
                }
            } else {
                if (!empty($userDagId)) {
                    $qList->add(
                        "SELECT DISTINCT record, sort FROM redcap_record_list WHERE project_id = ? AND dag_id = ? ORDER BY sort ASC, record ASC LIMIT ? OFFSET ?",
                        [$project_id, (int) $userDagId, $limit + 1, $offset]
                    );
                } else {
                    $qList->add(
                        "SELECT DISTINCT record, sort FROM redcap_record_list WHERE project_id = ? ORDER BY sort ASC, record ASC LIMIT ? OFFSET ?",
                        [$project_id, $limit + 1, $offset]
                    );
                }
            }
            $res = $qList->execute();
            while ($row = $res->fetch_assoc()) {
                $recordsInChunk[] = (string) $row['record'];
            }
        } else {
            $dataTable = $this->getDataTable($project_id);
            $qList = $this->createQuery();
            if ($autoIncSet && $sinceMaxPk !== null) {
                $qList->add(
                    "SELECT DISTINCT record FROM $dataTable WHERE project_id = ? AND (record + 0) > ? ORDER BY (record + 0) ASC LIMIT ?",
                    [$project_id, $sinceMaxPk, $limit + 1]
                );
            } else {
                $qList->add(
                    "SELECT DISTINCT record FROM $dataTable WHERE project_id = ? ORDER BY record ASC LIMIT ? OFFSET ?",
                    [$project_id, $limit + 1, $offset]
                );
            }
            $res = $qList->execute();
            while ($row = $res->fetch_assoc()) {
                $recordsInChunk[] = (string) $row['record'];
            }
        }

        if (count($recordsInChunk) > $limit) {
            $hasMore = true;
            array_pop($recordsInChunk);
        }

        if (empty($recordsInChunk)) {
            return [
                'locators' => (object) [],
                'count' => 0,
                'has_more' => false,
                'next_offset' => $offset,
                'max_pk' => $sinceMaxPk ?? 0,
                'auto_inc' => $autoIncSet,
            ];
        }

        if ($autoIncSet) {
            foreach ($recordsInChunk as $r) {
                if (is_numeric($r)) {
                    $num = (float) $r;
                    if ($maxPkInChunk === null || $num > $maxPkInChunk)
                        $maxPkInChunk = $num;
                }
            }
        }

        $dataTable = $this->getDataTable($project_id);
        $secondaryValues = [];
        $uniqueLookups = array_values(array_filter($uniqueFields, function ($f) use ($pk) {
            return $f !== $pk;
        }));

        if (!empty($uniqueLookups)) {
            $qSec = $this->createQuery();
            $qSec->add("SELECT record, field_name, value FROM $dataTable WHERE project_id = ? AND", [$project_id]);
            $qSec->addInClause('record', $recordsInChunk);
            $qSec->add("AND");
            $qSec->addInClause('field_name', $uniqueLookups);
            $resSec = $qSec->execute();
            while ($sRow = $resSec->fetch_assoc()) {
                $r = (string) $sRow['record'];
                $val = trim((string) $sRow['value']);
                if ($val !== '') {
                    $secondaryValues[$r][] = $val;
                }
            }
        }

        $recordLabels = [];
        $customLabel = $context['custom_record_label'] ?? '';
        $customLabelFields = $fieldConfig['custom_label_fields'] ?? [];
        if (!empty($customLabel) && !empty($customLabelFields) && empty($fieldConfig['needs_complex_piping'])) {
            $qLabels = $this->createQuery();
            $qLabels->add("SELECT record, field_name, value FROM $dataTable WHERE project_id = ? AND", [$project_id]);
            $qLabels->addInClause('record', $recordsInChunk);
            $qLabels->add("AND");
            $qLabels->addInClause('field_name', $customLabelFields);
            $resLabels = $qLabels->execute();
            $recFieldValues = [];
            while ($lRow = $resLabels->fetch_assoc()) {
                $rec = (string) $lRow['record'];
                $recFieldValues[$rec][(string) $lRow['field_name']] = (string) $lRow['value'];
            }
            foreach ($recordsInChunk as $rId) {
                if (isset($recFieldValues[$rId])) {
                    $search = [];
                    $replace = [];
                    foreach ($customLabelFields as $fname) {
                        $search[] = '[' . $fname . ']';
                        $replace[] = $recFieldValues[$rId][$fname] ?? '';
                    }
                    $lbl = trim(strip_tags((string) str_ireplace($search, $replace, $customLabel)));
                    $lbl = trim(preg_replace('/\(\s*\)|\[\s*\]|^[\s,\-:]+|[\s,\-:]+$/', '', $lbl));
                    if ($lbl !== '') {
                        $recordLabels[$rId] = $lbl;
                    }
                }
            }
        }

        $kv = [];
        foreach ($recordsInChunk as $recId) {
            $label = $recordLabels[$recId] ?? '';
            $pkKey = strtolower($recId);
            $kv[$pkKey] = [$recId, $label];

            if (isset($secondaryValues[$recId])) {
                foreach ($secondaryValues[$recId] as $secVal) {
                    $secKey = strtolower($secVal);
                    if (isset($kv[$secKey])) {
                        if ($kv[$secKey][0] !== $recId) {
                            $kv[$secKey] = null;
                        }
                    } else {
                        $kv[$secKey] = [$recId, $label];
                    }
                }
            }
        }

        $cleanKv = [];
        foreach ($kv as $k => $v) {
            if ($v !== null)
                $cleanKv[$k] = $v;
        }

        return [
            'locators' => (object) $cleanKv,
            'count' => count($recordsInChunk),
            'has_more' => $hasMore,
            'next_offset' => $offset + count($recordsInChunk),
            'max_pk' => $maxPkInChunk ?? ($sinceMaxPk ?? 0),
            'auto_inc' => $autoIncSet,
        ];
    }


    /**
     * Clean HTML tags from REDCap field/form labels.
     *
     * @param mixed $label
     * @return string
     */
    protected function cleanLabel($label)
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $label)));
    }
}
