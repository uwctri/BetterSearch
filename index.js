(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const cfg = window.BetterSearchConfig || {};
        const module = cfg.module;
        if (!module)
            return;

        const hasCustomLabel = Boolean(cfg.hasCustomLabel);
        const hasSecondaryPk = Boolean(cfg.hasSecondaryPk);
        const projectId = cfg.projectId || 0;
        const userId = cfg.userId || 'anon';
        const webroot = cfg.webroot || '';
        const fieldMetaMap = cfg.fieldMeta || {};
        const eventsMap = cfg.events || {};
        let selectedArm = cfg.selectedArm || 1;
        const totalColCount = 2 + (hasCustomLabel ? 1 : 0) + (hasSecondaryPk ? 1 : 0);

        if (window.location.search.indexOf('msg=') !== -1 && window.history && window.history.replaceState) {
            const cleanUrl = window.location.href.replace(/([?&])msg=[^&]*(&|$)/, (m, p1, p2) => (p1 === '?' && p2 ? '?' : (p2 ? p1 : '')));
            window.history.replaceState(null, '', cleanUrl);
        }

        const armTabs = document.querySelectorAll('.bs-arm-tab');
        const armCountLabelEl = document.getElementById('bs-arm-count-label');
        const armCountValEl = document.getElementById('bs-arm-count-val');
        const createArmLabelEl = document.getElementById('bs-create-arm-label');
        const classicViewLink = document.getElementById('bs-classic-view-link');

        const searchForm = document.getElementById('bs-search-form');
        const searchInput = document.getElementById('bs-search-input');
        const searchBtnLabel = document.getElementById('bs-search-btn-label');
        const quickOpenBanner = document.getElementById('bs-quick-open-banner');
        const quickOpenBtn = document.getElementById('bs-quick-open-btn');
        const qoIdEl = document.getElementById('bs-qo-id');
        const qoLabelEl = document.getElementById('bs-qo-label');
        const qoReasonEl = document.getElementById('bs-qo-reason');
        const chipBar = document.getElementById('bs-chip-bar');
        const recentRowEl = document.getElementById('bs-recent-records-row');
        const defaultPlaceholder = searchInput ? (searchInput.getAttribute('placeholder') || '') : '';

        const singleForm = cfg.singleForm || '';
        const multipleArms = Boolean(cfg.multipleArms);
        const userDagId = cfg.userDagId || 0;
        const autoIncSet = Boolean(cfg.autoIncSet);
        const locatorStorageKey = 'bs_loc_p' + projectId + '_d' + userDagId;

        let locatorMap = {};
        let locatorSyncInProgress = false;

        const getSessionLocators = () => {
            try {
                const raw = sessionStorage.getItem(locatorStorageKey);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        };

        const setSessionLocators = (data) => {
            try {
                sessionStorage.setItem(locatorStorageKey, JSON.stringify(data));
                return true;
            } catch (e) {
                // Quota exceeded; in-memory locatorMap continues to serve in RAM
                return false;
            }
        };

        // Clean up any persistent database from previous versions to maintain HIPAA compliance
        if (window.indexedDB && window.indexedDB.deleteDatabase) {
            try {
                window.indexedDB.deleteDatabase('BetterSearchDB');
            } catch (e) {}
        }

        // Immediately load any locators cached for this active tab session
        const initialSessionData = getSessionLocators();
        if (initialSessionData && initialSessionData.locators)
            locatorMap = initialSessionData.locators;

        const uniqueFields = new Set(cfg.uniqueFields || [cfg.pkField]);
        const pkField = cfg.pkField || 'record_id';
        const pkLabel = cfg.pkLabel || 'Record ID';
        let currentExactMatch = null;

        const dagFilterSelect = document.getElementById('bs-dag-filter');
        const enableDeepCheckbox = document.getElementById('bs-enable-deep');
        const showRecentCheckbox = document.getElementById('bs-show-recent');
        const defaultDagSettingSelect = document.getElementById('bs-default-dag-setting');
        const fuzzySlider = document.getElementById('bs-fuzzy-threshold');
        const fuzzyValLabel = document.getElementById('bs-fuzzy-threshold-val');
        const toggleSettingsBtn = document.getElementById('bs-toggle-settings-btn');
        const toggleSettingsLabel = document.getElementById('bs-toggle-settings-label');
        const settingsDrawer = document.getElementById('bs-settings-drawer');
        const activeSettingsBadgesEl = document.getElementById('bs-active-settings-badges');

        const resultsBox = document.getElementById('bs-results-box');
        const resultsTbody = document.getElementById('bs-results-tbody');
        const statusBadges = document.getElementById('bs-status-badges');
        const deepStatusEl = document.getElementById('bs-deep-status');

        const pageInfoEl = document.getElementById('bs-page-info');
        const pageControlsEl = document.getElementById('bs-page-controls');
        const pageSizeInput = document.getElementById('bs-page-size-input');

        const togglePriorityBtn = document.getElementById('bs-toggle-priority-btn');
        const priorityDrawer = document.getElementById('bs-priority-drawer');

        const addRecordBtn = document.getElementById('bs-add-record-btn');
        const manualIdForm = document.getElementById('bs-manual-id-form');
        const manualIdInput = document.getElementById('bs-manual-id-input');
        const newRecordDagSelect = document.getElementById('bs-new-record-dag');
        const createErrorEl = document.getElementById('bs-create-error');

        let activeSearchToken = 0;
        let baseRecords = [];
        let currentRecords = [];
        let activeChips = [];
        let currentPage = 1;
        let pageSize = 20;
        let fuzzyThreshold = fuzzySlider ? (parseInt(fuzzySlider.value, 10) || 100) : 100;
        let activeRowIndex = -1;

        const setCookie = (name, val) => {
            document.cookie = name + '=' + encodeURIComponent(String(val)) + '; path=/; max-age=31536000; SameSite=Lax';
        };

        const recentStorageKey = 'better_search_recent_' + projectId + '_' + userId;
        const sessionStateKey = 'better_search_state_' + projectId + '_' + userId;

        const fastPrefetchCache = new Map();
        let prefetchTimer = null;
        let prefetchInFlight = false;

        const buildFastCacheKey = (query, arm, dagFilter, fuzzy) =>
            String(arm || 1) + '|' + String(dagFilter || '') + '|' + String(fuzzy || 100) + '|' + query.trim().toLowerCase();

        const fetchFastSearch = (query, arm, dagFilter, fuzzy) => {
            const key = buildFastCacheKey(query, arm, dagFilter, fuzzy);
            const now = Date.now();
            const cached = fastPrefetchCache.get(key);
            if (cached && (now - cached.ts) < 60000)
                return cached.promise;

            if (fastPrefetchCache.size >= 25) {
                const oldestKey = fastPrefetchCache.keys().next().value;
                if (oldestKey !== undefined)
                    fastPrefetchCache.delete(oldestKey);
            }

            const promise = module.ajax('search_fast', {
                query: query.trim(),
                arm: arm,
                dag_filter: dagFilter,
                fuzzy_threshold: fuzzy
            }).catch((err) => {
                fastPrefetchCache.delete(key);
                throw err;
            });

            fastPrefetchCache.set(key, { promise: promise, ts: now });
            return promise;
        };

        const triggerSpeculativePrefetch = () => {
            if (!searchInput || activeChips.length > 0)
                return;

            const val = searchInput.value.trim();
            if (val.length < 2)
                return;

            const dagFilter = dagFilterSelect ? dagFilterSelect.value : '';
            const key = buildFastCacheKey(val, selectedArm, dagFilter, fuzzyThreshold);
            const cached = fastPrefetchCache.get(key);
            if (cached && (Date.now() - cached.ts) < 60000)
                return;

            if (prefetchInFlight)
                return;

            prefetchInFlight = true;
            fetchFastSearch(val, selectedArm, dagFilter, fuzzyThreshold)
                .then((res) => {
                    if (!searchInput)
                        return;
                    const latestVal = searchInput.value.trim();
                    if (latestVal.toLowerCase() === val.toLowerCase() && res && res.records)
                        updateAutoMatch(latestVal, res.records);
                })
                .catch(() => {})
                .finally(() => {
                    prefetchInFlight = false;
                    if (!searchInput || activeChips.length > 0)
                        return;
                    const latestVal = searchInput.value.trim();
                    if (latestVal.length >= 2 && latestVal.toLowerCase() !== val.toLowerCase())
                        scheduleSpeculativePrefetch();
                });
        };

        const scheduleSpeculativePrefetch = () => {
            if (prefetchTimer) {
                clearTimeout(prefetchTimer);
                prefetchTimer = null;
            }
            if (!searchInput || activeChips.length > 0)
                return;
            const val = searchInput.value.trim();
            if (val.length < 2)
                return;

            prefetchTimer = setTimeout(() => {
                prefetchTimer = null;
                triggerSpeculativePrefetch();
            }, 220);
        };

        const escapeHtml = (str) => String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const formatElapsed = (ms) => {
            const num = Number(ms) || 0;
            return num >= 1000 ? (num / 1000).toFixed(2) + 's' : num + ' ms';
        };

        const buildRecordUrl = (recId) => {
            return webroot + (singleForm
                ? ('DataEntry/index.php?pid=' + projectId + '&id=' + encodeURIComponent(recId) + '&page=' + encodeURIComponent(singleForm))
                : ('DataEntry/record_home.php?pid=' + projectId + '&id=' + encodeURIComponent(recId) + (multipleArms ? ('&arm=' + selectedArm) : '')));
        };

        const checkInstantLocator = (query) => {
            const cleanQ = (query || '').trim().toLowerCase();
            if (!cleanQ || !locatorMap)
                return null;

            const entry = locatorMap[cleanQ];
            if (entry && Array.isArray(entry)) {
                const [recId, customLabel] = entry;
                const recStr = String(recId);
                const isPk = (recStr.toLowerCase() === cleanQ);
                return {
                    record: recStr,
                    record_url: buildRecordUrl(recStr),
                    fieldLabel: isPk ? pkLabel : 'Identifier',
                    matchedValue: query.trim(),
                    customLabel: customLabel || '',
                    isPk: isPk
                };
            }

            return null;
        };

        const findExactAutoMatch = (query, records) => {
            const cleanQ = (query || '').trim().toLowerCase();
            if (!cleanQ || !records || records.length === 0)
                return null;

            // 1. Direct match on Record ID (Primary Key)
            const pkMatches = [];
            for (let i = 0; i < records.length; i++) {
                const item = records[i];
                const rec = item.rec || item;
                const recId = String(rec.record || '').trim().toLowerCase();
                if (recId === cleanQ) {
                    pkMatches.push({
                        record: rec.record,
                        record_url: rec.record_url || buildRecordUrl(rec.record),
                        fieldLabel: pkLabel,
                        matchedValue: rec.record,
                        customLabel: rec.custom_label || '',
                        isPk: true
                    });
                }
            }
            if (pkMatches.length === 1)
                return pkMatches[0];
            if (pkMatches.length > 1)
                return null; // Ambiguous Record ID matches

            // 2. Exact match on Secondary Unique Field or configured unique identifier fields
            const fieldMatches = [];
            const seenRecIds = new Set();

            for (let i = 0; i < records.length; i++) {
                const item = records[i];
                const rec = item.rec || item;
                const recId = String(rec.record || '').trim();

                let recordMatched = null;

                if (rec.secondary_pk_value && String(rec.secondary_pk_value).trim().toLowerCase() === cleanQ) {
                    recordMatched = {
                        record: rec.record,
                        record_url: rec.record_url || buildRecordUrl(rec.record),
                        fieldLabel: 'Secondary ID',
                        matchedValue: rec.secondary_pk_value,
                        customLabel: rec.custom_label || '',
                        isPk: false
                    };
                }

                if (!recordMatched) {
                    const matches = rec.matches || [];
                    for (let m = 0; m < matches.length; m++) {
                        const hit = matches[m];
                        const isTuple = Array.isArray(hit);
                        const fname = isTuple ? hit[0] : hit.field_name;
                        if (!uniqueFields.has(fname))
                            continue;

                        const rawVal = String(isTuple ? (hit[1] || '') : (hit.raw_value || '')).trim().toLowerCase();
                        const val = String(isTuple ? (hit[2] || '') : (hit.value || '')).trim().toLowerCase();

                        if (rawVal === cleanQ || val === cleanQ) {
                            const fMeta = (fieldMetaMap && fieldMetaMap[fname]) ? fieldMetaMap[fname] : [fname];
                            recordMatched = {
                                record: rec.record,
                                record_url: rec.record_url || buildRecordUrl(rec.record),
                                fieldLabel: fMeta[0] || fname,
                                matchedValue: isTuple ? hit[2] : hit.value,
                                customLabel: rec.custom_label || '',
                                isPk: false
                            };
                            break;
                        }
                    }
                }

                if (recordMatched && !seenRecIds.has(recId)) {
                    seenRecIds.add(recId);
                    fieldMatches.push(recordMatched);
                    if (fieldMatches.length > 1)
                        return null; // More than 1 record matches -> ambiguous
                }
            }

            return fieldMatches.length === 1 ? fieldMatches[0] : null;
        };

        const updateAutoMatch = (query, candidateRecords) => {
            const cleanQ = (query || '').trim();
            if (!cleanQ) {
                currentExactMatch = null;
                if (quickOpenBanner)
                    quickOpenBanner.style.display = 'none';
                return null;
            }

            let match = null;
            if (candidateRecords && candidateRecords.length > 0)
                match = findExactAutoMatch(cleanQ, candidateRecords);

            if (!match)
                match = checkInstantLocator(cleanQ);

            currentExactMatch = match;

            if (quickOpenBanner) {
                if (match && match.record_url) {
                    quickOpenBanner.style.display = 'flex';
                    if (qoIdEl)
                        qoIdEl.textContent = match.record;
                    if (qoLabelEl) {
                        if (match.customLabel)
                            qoLabelEl.textContent = '— ' + match.customLabel;
                        else
                            qoLabelEl.textContent = '';
                    }
                    if (qoReasonEl) {
                        if (!match.isPk)
                            qoReasonEl.textContent = '(' + match.fieldLabel + ': ' + match.matchedValue + ')';
                        else
                            qoReasonEl.textContent = '';
                    }
                } else {
                    quickOpenBanner.style.display = 'none';
                }
            }
            return match;
        };

        const scheduleIdleTask = (callback, delayMs) => {
            if (typeof window.requestIdleCallback === 'function') {
                setTimeout(() => {
                    window.requestIdleCallback(() => callback(), { timeout: 3000 });
                }, delayMs || 800);
            } else {
                setTimeout(callback, delayMs || 1000);
            }
        };

        const startLocatorSync = async () => {
            if (locatorSyncInProgress)
                return;
            locatorSyncInProgress = true;

            try {
                const cached = getSessionLocators();
                let needsFullSync = true;
                let currentMaxPk = 0;

                if (cached && cached.locators) {
                    locatorMap = cached.locators;
                    currentMaxPk = cached.maxPk || 0;
                    needsFullSync = false;
                    if (searchInput && searchInput.value.trim())
                        updateAutoMatch(searchInput.value.trim(), currentRecords);
                }

                if (!needsFullSync && autoIncSet) {
                    const res = await module.ajax('fetch_locators', {
                        since_max_pk: currentMaxPk,
                        limit: 5000
                    });
                    if (res && res.locators && Object.keys(res.locators).length > 0) {
                        Object.assign(locatorMap, res.locators);
                        const updatedMax = (res.max_pk && res.max_pk > currentMaxPk) ? res.max_pk : currentMaxPk;
                        setSessionLocators({
                            maxPk: updatedMax,
                            locators: locatorMap
                        });
                        if (searchInput && searchInput.value.trim())
                            updateAutoMatch(searchInput.value.trim(), currentRecords);
                    }
                } else if (needsFullSync) {
                    let offset = 0;
                    let hasMore = true;
                    const tempLocators = {};
                    let runningMaxPk = 0;

                    while (hasMore) {
                        if (prefetchInFlight) {
                            await new Promise((r) => setTimeout(r, 1200));
                            continue;
                        }

                        const res = await module.ajax('fetch_locators', {
                            offset: offset,
                            limit: 5000
                        });

                        if (!res || !res.locators)
                            break;

                        Object.assign(tempLocators, res.locators);
                        Object.assign(locatorMap, res.locators);

                        if (searchInput && searchInput.value.trim())
                            updateAutoMatch(searchInput.value.trim(), currentRecords);

                        if (res.max_pk && res.max_pk > runningMaxPk)
                            runningMaxPk = res.max_pk;

                        hasMore = Boolean(res.has_more);
                        offset = res.next_offset || (offset + (res.count || 0));

                        if (hasMore)
                            await new Promise((r) => setTimeout(r, 300));
                    }

                    setSessionLocators({
                        maxPk: runningMaxPk,
                        locators: tempLocators
                    });
                    locatorMap = tempLocators;
                }
            } catch (err) {
                // Silently fallback to runtime fast search
            } finally {
                locatorSyncInProgress = false;
            }
        };

        const getAllHighlightTerms = () => {
            const terms = activeChips.slice();
            const liveVal = searchInput ? searchInput.value.trim() : '';
            if (liveVal)
                terms.push(liveVal);
            return terms.join(' ');
        };

        const highlightMatch = (text, rawQuery) => {
            const safeText = escapeHtml(text);
            if (!rawQuery || !safeText)
                return safeText;

            let tokens = rawQuery.trim().split(/[\s,]+/).filter((t) => t.length >= 2);
            if (tokens.length === 0)
                tokens = [rawQuery.trim()];

            const patterns = tokens
                .map((t) => escapeHtml(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
                .filter(Boolean);

            if (patterns.length === 0)
                return safeText;

            const regex = new RegExp('(' + patterns.join('|') + ')', 'gi');
            return safeText.replace(regex, '<span class="bs-hl">$1</span>');
        };

        const snippetAndHighlightMatch = (text, rawQuery, maxLen) => {
            const normalized = String(text == null ? '' : text).replace(/\s+/g, ' ').trim();
            const limit = maxLen || 110;
            if (normalized.length <= limit)
                return { html: highlightMatch(normalized, rawQuery), truncated: false, full: normalized };

            const lower = normalized.toLowerCase();
            let matchIdx = -1;
            if (rawQuery) {
                const qTrim = rawQuery.trim().toLowerCase();
                matchIdx = lower.indexOf(qTrim);
                if (matchIdx === -1) {
                    const tokens = qTrim.split(/[\s,]+/).filter((t) => t.length >= 2);
                    for (let i = 0; i < tokens.length; i++) {
                        const idx = lower.indexOf(tokens[i]);
                        if (idx !== -1) {
                            matchIdx = idx;
                            break;
                        }
                    }
                }
            }

            let start = 0;
            if (matchIdx > Math.floor(limit * 0.4))
                start = Math.min(matchIdx - Math.floor(limit * 0.35), normalized.length - limit);

            const slice = normalized.substr(start, limit);
            const prefix = start > 0 ? '\u2026' : '';
            const suffix = (start + limit) < normalized.length ? '\u2026' : '';

            return {
                html: prefix + highlightMatch(slice, rawQuery) + suffix,
                truncated: true,
                full: normalized
            };
        };

        const buildTermVariants = (rawTerm) => {
            const lower = rawTerm.trim().toLowerCase();
            const variants = [lower];

            const mdyMatch = lower.match(/^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$/);
            if (mdyMatch) {
                const mm = mdyMatch[1].padStart(2, '0');
                const dd = mdyMatch[2].padStart(2, '0');
                const yyyy = mdyMatch[3].length === 2 ? ('19' + mdyMatch[3]) : mdyMatch[3];
                variants.push(yyyy + '-' + mm + '-' + dd);
            }

            const digits = lower.replace(/\D+/g, '');
            if (digits.length >= 7 && digits.length <= 11 && /^[\d\s\-().+]+$/.test(lower))
                variants.push(digits);

            return variants;
        };

        const damerauLevenshteinJs = (a, b) => {
            const lenA = a.length;
            const lenB = b.length;
            const d = [];
            for (let i = 0; i <= lenA; i++)
                d[i] = [i];
            for (let j = 0; j <= lenB; j++)
                d[0][j] = j;

            for (let i = 1; i <= lenA; i++) {
                for (let j = 1; j <= lenB; j++) {
                    const cost = a[i - 1] === b[j - 1] ? 0 : 1;
                    d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
                    if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1])
                        d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
                }
            }
            return d[lenA][lenB];
        };

        const textContainsVariant = (haystack, variants) => {
            if (!haystack)
                return false;
            const lower = String(haystack).toLowerCase();
            for (let i = 0; i < variants.length; i++)
                if (lower.indexOf(variants[i]) !== -1)
                    return true;

            if (variants.length > 1) {
                const hayDigits = lower.replace(/\D+/g, '');
                for (let j = 1; j < variants.length; j++)
                    if (/^\d{7,11}$/.test(variants[j]) && hayDigits.indexOf(variants[j]) !== -1)
                        return true;
            }

            if (variants.length > 0 && variants[0]) {
                const qWords = variants[0].split(/[\s,;:\-/().]+/).filter((w) => w.length >= 2);
                if (qWords.length > 1) {
                    const hayWords = lower.split(/[\s,;:\-/().]+/).filter(Boolean);
                    let allMatched = true;
                    for (let qw = 0; qw < qWords.length; qw++) {
                        const targetWord = qWords[qw];
                        if (lower.indexOf(targetWord) !== -1)
                            continue;
                        if (fuzzyThreshold >= 100) {
                            allMatched = false;
                            break;
                        }
                        let wordFound = false;
                        for (let hw = 0; hw < hayWords.length; hw++) {
                            const cand = hayWords[hw];
                            const maxLen = Math.max(targetWord.length, cand.length);
                            if (maxLen === 0 || maxLen > 64 || Math.abs(targetWord.length - cand.length) > Math.ceil(maxLen * 0.5))
                                continue;
                            const dist = damerauLevenshteinJs(targetWord, cand);
                            if (((1 - (dist / maxLen)) * 100) >= fuzzyThreshold) {
                                wordFound = true;
                                break;
                            }
                        }
                        if (!wordFound) {
                            allMatched = false;
                            break;
                        }
                    }
                    if (allMatched)
                        return true;
                } else if (fuzzyThreshold < 100 && variants[0].length >= 3) {
                    const qWord = variants[0];
                    const words = lower.split(/[\s,;:\-/().]+/).filter(Boolean);
                    for (let w = 0; w < words.length; w++) {
                        const cand = words[w];
                        const maxLen = Math.max(qWord.length, cand.length);
                        if (maxLen === 0 || maxLen > 64 || Math.abs(qWord.length - cand.length) > Math.ceil(maxLen * 0.5))
                            continue;
                        const dist = damerauLevenshteinJs(qWord, cand);
                        const pct = (1 - (dist / maxLen)) * 100;
                        if (pct >= fuzzyThreshold)
                            return true;
                    }
                }
            }

            return false;
        };

        const expandCompactField = (recId, tuple) => {
            if (!Array.isArray(tuple))
                return tuple;
            const fname = tuple[0];
            const formatted = tuple[1];
            const rawVal = tuple[2];
            const evId = tuple[3] || 0;
            const inst = tuple[4] || 1;
            const locked = Boolean(tuple[5]);
            const meta = fieldMetaMap[fname] || [fname, '', fname];
            const fieldLabel = meta[0] || fname;
            const formName = meta[1] || '';
            const formLabel = meta[2] || formName;
            const eventName = eventsMap[evId] || '';
            const formUrl = webroot + 'DataEntry/index.php?pid=' + projectId
                + '&id=' + encodeURIComponent(recId)
                + '&page=' + encodeURIComponent(formName)
                + (evId ? ('&event_id=' + evId) : '')
                + (inst > 1 ? ('&instance=' + inst) : '')
                + '&fldfocus=' + encodeURIComponent(fname) + '#' + fname + '-tr';

            return {
                field_name: fname,
                field_label: fieldLabel,
                form_name: formName,
                form_label: formLabel,
                event_id: evId,
                event_name: eventName,
                instance: inst,
                value: formatted,
                raw_value: rawVal,
                form_url: formUrl,
                locked: locked
            };
        };

        const matchRecordAgainstTerms = (rec, terms) => {
            const matchedFieldsMap = {};
            const initialMatches = rec.initial_matches || rec.matches || [];
            for (let i = 0; i < initialMatches.length; i++) {
                const m = initialMatches[i];
                const key = m.field_name + '|' + m.event_id + '|' + m.instance;
                matchedFieldsMap[key] = m;
            }

            const allFields = rec.fields || [];
            const searchableFields = allFields.slice();
            for (let im = 0; im < initialMatches.length; im++)
                searchableFields.push(initialMatches[im]);

            for (let t = 0; t < terms.length; t++) {
                const rawTerm = terms[t].trim();
                if (!rawTerm)
                    continue;

                const subTokens = rawTerm.split(/[\s,]+/).filter(Boolean);
                const tokensToMatch = subTokens.length > 1 ? subTokens : [rawTerm];

                for (let s = 0; s < tokensToMatch.length; s++) {
                    const variants = buildTermVariants(tokensToMatch[s]);
                    let tokenMatched = false;

                    if (
                        textContainsVariant(rec.record, variants)
                        || textContainsVariant(rec.custom_label, variants)
                        || textContainsVariant(rec.secondary_pk_value, variants)
                        || textContainsVariant(rec.dag_name, variants)
                    )
                        tokenMatched = true;

                    for (let f = 0; f < searchableFields.length; f++) {
                        const item = searchableFields[f];
                        const isTuple = Array.isArray(item);
                        const fname = isTuple ? item[0] : item.field_name;
                        const val = isTuple ? item[1] : item.value;
                        const rawVal = isTuple ? item[2] : item.raw_value;
                        const evId = isTuple ? (item[3] || 0) : item.event_id;
                        const inst = isTuple ? (item[4] || 1) : item.instance;
                        const fLabel = isTuple ? ((fieldMetaMap[fname] && fieldMetaMap[fname][0]) || fname) : (item.field_label || fname);

                        if (
                            textContainsVariant(val, variants)
                            || textContainsVariant(rawVal, variants)
                            || textContainsVariant(fLabel, variants)
                        ) {
                            tokenMatched = true;
                            const fKey = fname + '|' + evId + '|' + inst;
                            if (!matchedFieldsMap[fKey] && Object.keys(matchedFieldsMap).length < 10)
                                matchedFieldsMap[fKey] = isTuple ? expandCompactField(rec.record, item) : item;
                        }
                    }

                    if (!tokenMatched)
                        return null;
                }
            }

            return Object.values(matchedFieldsMap);
        };

        const applyClientFilters = () => {
            const terms = activeChips.slice();
            const liveVal = (activeChips.length > 0 && searchInput) ? searchInput.value.trim() : '';
            if (liveVal)
                terms.push(liveVal);

            if (terms.length <= 1 && !liveVal) {
                currentRecords = baseRecords.map((item) => ({
                    rec: Object.assign({}, item.rec, { matches: item.rec.initial_matches || item.rec.matches }),
                    tier: item.tier
                }));
            } else {
                const filtered = [];
                for (let i = 0; i < baseRecords.length; i++) {
                    const item = baseRecords[i];
                    const matchedFields = matchRecordAgainstTerms(item.rec, terms);
                    if (matchedFields !== null)
                        filtered.push({
                            rec: Object.assign({}, item.rec, { matches: matchedFields }),
                            tier: item.tier
                        });
                }
                currentRecords = filtered;
            }

            const qToTest = activeChips.length > 0 ? activeChips[0] : (searchInput ? searchInput.value.trim() : '');
            const autoMatch = updateAutoMatch(qToTest, currentRecords);
            if (autoMatch) {
                const idx = currentRecords.findIndex((item) => String(item.rec.record) === String(autoMatch.record));
                if (idx > 0) {
                    const [exactItem] = currentRecords.splice(idx, 1);
                    currentRecords.unshift(exactItem);
                }
            }

            currentPage = 1;
            activeRowIndex = -1;

            if (currentRecords.length > 0)
                renderCurrentPage();
            else if (baseRecords.length > 0)
                renderEmptyState('No records in the current result set match all active filter chips.');
        };

        const updateSearchBoxMode = () => {
            if (!searchInput)
                return;
            if (activeChips.length > 0) {
                searchInput.setAttribute('placeholder', 'Type to narrow these results further (press Enter to stack filter chip)...');
                if (searchBtnLabel)
                    searchBtnLabel.textContent = 'Add Filter';
            } else {
                searchInput.setAttribute('placeholder', defaultPlaceholder);
                if (searchBtnLabel)
                    searchBtnLabel.textContent = 'Search';
            }
        };

        const getRecentRecords = () => {
            try {
                const raw = localStorage.getItem(recentStorageKey);
                if (!raw)
                    return [];
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        };

        const saveRecentRecord = (rec) => {
            if (!rec || !rec.record)
                return;
            try {
                const list = getRecentRecords().filter((item) => String(item.record) !== String(rec.record));
                list.unshift({
                    record: String(rec.record),
                    custom_label: rec.custom_label || '',
                    record_url: rec.record_url || ''
                });
                if (list.length > 8)
                    list.length = 8;
                localStorage.setItem(recentStorageKey, JSON.stringify(list));
            } catch (e) {}
        };

        const saveSearchStateToSession = (leavingForRecord) => {
            try {
                if (activeChips.length === 0) {
                    sessionStorage.removeItem(sessionStateKey);
                    return;
                }
                sessionStorage.setItem(sessionStateKey, JSON.stringify({
                    activeChips: activeChips,
                    baseRecords: baseRecords,
                    currentPage: currentPage,
                    selectedArm: selectedArm,
                    dagFilter: dagFilterSelect ? dagFilterSelect.value : '',
                    statusBadgesHtml: statusBadges ? statusBadges.innerHTML : '',
                    deepStatusHtml: deepStatusEl ? deepStatusEl.innerHTML : '',
                    leavingForRecord: Boolean(leavingForRecord),
                    ts: Date.now()
                }));
            } catch (e) {}
        };

        const renderRecentRecords = () => {
            if (!recentRowEl)
                return;
            const showRecent = !showRecentCheckbox || showRecentCheckbox.checked;
            if (!showRecent || activeChips.length > 0) {
                recentRowEl.style.display = 'none';
                recentRowEl.innerHTML = '';
                return;
            }

            const recent = getRecentRecords();
            if (recent.length === 0) {
                recentRowEl.style.display = 'none';
                recentRowEl.innerHTML = '';
                return;
            }

            recentRowEl.style.display = 'flex';
            recentRowEl.innerHTML = '<span class="bs-recent-label"><i class="fas fa-history"></i> Recent:</span>';

            for (let i = 0; i < recent.length; i++) {
                const item = recent[i];
                const a = document.createElement('a');
                a.className = 'bs-recent-chip';
                a.href = item.record_url;
                let inner = '<span>' + escapeHtml(item.record) + '</span>';
                if (item.custom_label)
                    inner += '<span class="bs-recent-chip-lbl">(' + escapeHtml(item.custom_label) + ')</span>';
                a.innerHTML = inner;
                a.addEventListener('click', () => saveRecentRecord(item));
                recentRowEl.appendChild(a);
            }

            const clearRecentBtn = document.createElement('button');
            clearRecentBtn.type = 'button';
            clearRecentBtn.className = 'bs-link-btn';
            clearRecentBtn.style.marginLeft = 'auto';
            clearRecentBtn.textContent = 'Clear';
            clearRecentBtn.addEventListener('click', () => {
                try {
                    localStorage.removeItem(recentStorageKey);
                } catch (e) {}
                renderRecentRecords();
            });
            recentRowEl.appendChild(clearRecentBtn);
        };

        const clearAllChips = () => {
            activeSearchToken++;
            activeChips = [];
            baseRecords = [];
            currentRecords = [];
            currentPage = 1;
            activeRowIndex = -1;
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            renderChipBar();
            updateSearchBoxMode();
            renderRecentRecords();
            saveSearchStateToSession(false);
            if (resultsBox)
                resultsBox.style.display = 'none';
        };

        const removeChipAt = (idx) => {
            if (idx < 0 || idx >= activeChips.length)
                return;

            const removedFirstChip = (idx === 0);
            activeChips.splice(idx, 1);

            if (activeChips.length === 0)
                return clearAllChips();

            renderChipBar();
            updateSearchBoxMode();

            if (removedFirstChip) {
                const newPrimary = activeChips[0];
                const remaining = activeChips.slice(1);
                activeChips = [newPrimary];
                runPrimarySearch(newPrimary, remaining);
            } else {
                applyClientFilters();
            }
        };

        const renderChipBar = () => {
            if (!chipBar)
                return;
            if (activeChips.length === 0) {
                chipBar.style.display = 'none';
                chipBar.innerHTML = '';
                return;
            }

            chipBar.style.display = 'flex';
            chipBar.innerHTML = '<span class="bs-chip-bar-label"><i class="fas fa-filter"></i> Active filters:</span>';

            for (let i = 0; i < activeChips.length; i++) {
                const chip = document.createElement('span');
                chip.className = 'bs-filter-chip';
                chip.innerHTML = '<span>' + escapeHtml(activeChips[i]) + '</span>';

                const rmBtn = document.createElement('button');
                rmBtn.type = 'button';
                rmBtn.className = 'bs-filter-chip-remove';
                rmBtn.title = 'Remove filter';
                rmBtn.innerHTML = '&times;';
                rmBtn.addEventListener('click', () => removeChipAt(i));

                chip.appendChild(rmBtn);
                chipBar.appendChild(chip);
            }

            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'bs-link-btn';
            clearBtn.style.marginLeft = 'auto';
            clearBtn.textContent = 'Clear all';
            clearBtn.addEventListener('click', () => clearAllChips());
            chipBar.appendChild(clearBtn);
        };

        const renderRecordRow = (rec, tierNum, queryStr) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-record-url', rec.record_url);

            const isExact = currentExactMatch && String(rec.record) === String(currentExactMatch.record);
            if (isExact)
                tr.classList.add('bs-exact-match-row');

            const tdId = document.createElement('td');
            const exactBadge = isExact
                ? '<span class="bs-pill bs-pill-exact" title="Exact match on ' + escapeHtml(currentExactMatch.fieldLabel) + ': ' + escapeHtml(currentExactMatch.matchedValue) + '"><i class="fas fa-bullseye"></i> Exact Match</span>'
                : '';

            const tierBadge = tierNum === 1
                ? '<span class="bs-tier-badge bs-tier-fast" title="Matched in fast search">Fast</span>'
                : '<span class="bs-tier-badge bs-tier-deep" title="Matched in deep search">Deep</span>';

            let idHtml = '<a class="bs-rec-id" href="' + escapeHtml(rec.record_url) + '">'
                + highlightMatch(rec.record, queryStr) + '</a>' + exactBadge + tierBadge;

            let metaTags = '';
            if (rec.dag_name)
                metaTags += '<span class="bs-meta-tag" title="Data Access Group"><i class="fas fa-users"></i> ' + escapeHtml(rec.dag_name) + '</span>';
            if (rec.arms && rec.arms.length > 0)
                for (let a = 0; a < rec.arms.length; a++)
                    metaTags += '<span class="bs-meta-tag" title="Arm">' + escapeHtml(rec.arms[a]) + '</span>';

            if (metaTags)
                idHtml += '<div>' + metaTags + '</div>';

            tdId.innerHTML = idHtml;
            tr.appendChild(tdId);

            if (hasCustomLabel) {
                const tdLabel = document.createElement('td');
                if (rec.custom_label) {
                    const lblSnippet = snippetAndHighlightMatch(rec.custom_label, queryStr, 90);
                    tdLabel.innerHTML = lblSnippet.html;
                    if (lblSnippet.truncated)
                        tdLabel.title = lblSnippet.full;
                } else {
                    tdLabel.innerHTML = '<span style="color:#aaa;">&mdash;</span>';
                }
                tr.appendChild(tdLabel);
            }

            if (hasSecondaryPk) {
                const tdSec = document.createElement('td');
                tdSec.innerHTML = rec.secondary_pk_value
                    ? highlightMatch(rec.secondary_pk_value, queryStr)
                    : '<span style="color:#aaa;">&mdash;</span>';
                tr.appendChild(tdSec);
            }

            const tdMatches = document.createElement('td');
            const matches = rec.matches || [];
            let matchesHtml = '';
            for (let m = 0; m < matches.length; m++) {
                const hit = matches[m];
                const contextParts = [];
                if (hit.event_name)
                    contextParts.push(escapeHtml(hit.event_name));
                if (hit.instance && hit.instance > 1)
                    contextParts.push('#' + hit.instance);

                const contextBadge = contextParts.length > 0
                    ? ' <span style="color:#666;font-size:11px;">(' + contextParts.join(' &bull; ') + ')</span>'
                    : '';

                const valSnippet = snippetAndHighlightMatch(hit.value, queryStr, 110);
                const valTitleAttr = valSnippet.truncated ? ' title="' + escapeHtml(valSnippet.full) + '"' : '';
                const lockHtml = hit.locked
                    ? ' <i class="fas fa-lock bs-lock-icon" title="Form is locked"></i>'
                    : '';

                matchesHtml += '<div class="bs-hit-row">'
                    + '<span class="bs-hit-label">' + escapeHtml(hit.field_label) + ':</span> '
                    + '<span class="bs-hit-val"' + valTitleAttr + '>' + valSnippet.html + '</span>'
                    + contextBadge
                    + '<a class="bs-hit-form-link" href="' + escapeHtml(hit.form_url) + '" title="Open ' + escapeHtml(hit.form_label) + '">'
                    + '<i class="fas fa-external-link-alt"></i> ' + escapeHtml(hit.form_label) + lockHtml
                    + '</a>'
                    + '</div>';
            }
            tdMatches.innerHTML = matchesHtml;
            tr.appendChild(tdMatches);

            const links = tr.querySelectorAll('a.bs-rec-id, a.bs-hit-form-link');
            links.forEach((lnk) => {
                lnk.addEventListener('click', () => {
                    saveRecentRecord(rec);
                    saveSearchStateToSession(true);
                });
            });

            return tr;
        };

        const renderEmptyState = (message) => {
            resultsTbody.innerHTML = '<tr><td colspan="' + totalColCount + '" class="bs-empty">' + escapeHtml(message) + '</td></tr>';
            if (pageInfoEl)
                pageInfoEl.textContent = 'Showing 0 results';
            if (pageControlsEl)
                pageControlsEl.innerHTML = '';
        };

        const buildPageNumberList = (current, total) => {
            if (total <= 7) {
                const all = [];
                for (let i = 1; i <= total; i++)
                    all.push(i);
                return all;
            }

            const list = [1];
            let start = Math.max(2, current - 1);
            let end = Math.min(total - 1, current + 1);

            if (current <= 3)
                end = 4;
            if (current >= total - 2)
                start = total - 3;

            if (start > 2)
                list.push('...');
            for (let p = start; p <= end; p++)
                list.push(p);
            if (end < total - 1)
                list.push('...');

            list.push(total);
            return list;
        };

        const createPageButton = (label, targetPage, disabled, isActive) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bs-page-btn' + (isActive ? ' active' : '');
            btn.textContent = label;
            btn.disabled = Boolean(disabled);
            if (!disabled && !isActive)
                btn.addEventListener('click', () => {
                    currentPage = targetPage;
                    activeRowIndex = -1;
                    renderCurrentPage();
                    saveSearchStateToSession(false);
                });
            return btn;
        };

        const renderPaginationControls = (totalPages) => {
            if (!pageControlsEl)
                return;
            pageControlsEl.innerHTML = '';
            if (totalPages <= 1)
                return;

            pageControlsEl.appendChild(createPageButton('\u00AB Prev', currentPage - 1, currentPage === 1, false));

            const pages = buildPageNumberList(currentPage, totalPages);
            for (let i = 0; i < pages.length; i++) {
                const p = pages[i];
                if (p === '...') {
                    const span = document.createElement('span');
                    span.className = 'bs-page-ellipsis';
                    span.textContent = '\u2026';
                    pageControlsEl.appendChild(span);
                } else {
                    pageControlsEl.appendChild(createPageButton(String(p), p, false, p === currentPage));
                }
            }

            pageControlsEl.appendChild(createPageButton('Next \u00BB', currentPage + 1, currentPage === totalPages, false));
        };

        const renderCurrentPage = () => {
            const total = currentRecords.length;
            if (total === 0)
                return;

            const totalPages = Math.max(1, Math.ceil(total / pageSize));
            if (currentPage > totalPages)
                currentPage = totalPages;
            if (currentPage < 1)
                currentPage = 1;

            const startIdx = (currentPage - 1) * pageSize;
            const endIdx = Math.min(startIdx + pageSize, total);
            const highlightTerms = getAllHighlightTerms();

            resultsTbody.innerHTML = '';
            for (let i = startIdx; i < endIdx; i++) {
                const item = currentRecords[i];
                resultsTbody.appendChild(renderRecordRow(item.rec, item.tier, highlightTerms));
            }

            if (pageInfoEl) {
                const suffix = (baseRecords.length !== total)
                    ? ' (filtered from ' + baseRecords.length + ')'
                    : '';
                pageInfoEl.textContent = 'Showing ' + (startIdx + 1) + '\u2013' + endIdx + ' of ' + total + ' matching ' + (total === 1 ? 'record' : 'records') + suffix;
            }

            renderPaginationControls(totalPages);
        };

        const applyPageSizeFromInput = (normalizeInput) => {
            if (!pageSizeInput)
                return;
            let parsed = parseInt(pageSizeInput.value, 10);
            if (isNaN(parsed) || parsed < 1) {
                if (normalizeInput)
                    pageSizeInput.value = String(pageSize);
                return;
            }
            if (parsed > 500)
                parsed = 500;
            if (normalizeInput)
                pageSizeInput.value = String(parsed);
            if (parsed === pageSize)
                return;

            pageSize = parsed;
            setCookie('better_search_page_size', parsed);
            currentPage = 1;
            activeRowIndex = -1;
            if (currentRecords.length > 0)
                renderCurrentPage();
        };

        if (pageSizeInput) {
            const initialSize = parseInt(pageSizeInput.value, 10);
            if (!isNaN(initialSize) && initialSize > 0)
                pageSize = initialSize;

            pageSizeInput.addEventListener('change', () => applyPageSizeFromInput(true));
            pageSizeInput.addEventListener('input', () => applyPageSizeFromInput(false));
        }

        const updateActiveSettingsBadges = () => {
            if (!activeSettingsBadgesEl)
                return;
            let badges = '';
            if (enableDeepCheckbox && !enableDeepCheckbox.checked)
                badges += '<span class="bs-setting-badge" title="Deep search is disabled in user settings">Deep: Off</span>';
            if (fuzzyThreshold < 100)
                badges += '<span class="bs-setting-badge" title="Fuzzy matching enabled">Fuzzy: ' + fuzzyThreshold + '%</span>';
            activeSettingsBadgesEl.innerHTML = badges;
        };

        if (toggleSettingsBtn && settingsDrawer)
            toggleSettingsBtn.addEventListener('click', () => {
                const hidden = settingsDrawer.style.display === 'none' || settingsDrawer.style.display === '';
                settingsDrawer.style.display = hidden ? 'block' : 'none';
                if (toggleSettingsLabel)
                    toggleSettingsLabel.textContent = hidden ? 'Hide user settings' : 'User settings';
            });

        if (enableDeepCheckbox)
            enableDeepCheckbox.addEventListener('change', () => {
                setCookie('better_search_enable_deep', enableDeepCheckbox.checked ? '1' : '0');
                updateActiveSettingsBadges();
            });

        if (showRecentCheckbox)
            showRecentCheckbox.addEventListener('change', () => {
                setCookie('better_search_show_recent', showRecentCheckbox.checked ? '1' : '0');
                renderRecentRecords();
            });

        if (defaultDagSettingSelect)
            defaultDagSettingSelect.addEventListener('change', () => {
                const val = defaultDagSettingSelect.value;
                setCookie('better_search_default_dag_' + projectId, val);
                if (dagFilterSelect && dagFilterSelect.value !== val) {
                    dagFilterSelect.value = val;
                    if (activeChips.length > 0)
                        runPrimarySearch(activeChips[0], activeChips.slice(1));
                }
            });

        if (fuzzySlider) {
            fuzzySlider.addEventListener('input', () => {
                const val = parseInt(fuzzySlider.value, 10) || 100;
                fuzzyThreshold = val;
                if (fuzzyValLabel)
                    fuzzyValLabel.textContent = val >= 100 ? '100% (Off)' : (val + '%');
                updateActiveSettingsBadges();
            });

            fuzzySlider.addEventListener('change', () => {
                const val = parseInt(fuzzySlider.value, 10) || 100;
                fuzzyThreshold = val;
                setCookie('better_search_fuzzy_threshold', val);
                updateActiveSettingsBadges();
                if (activeChips.length > 0)
                    runPrimarySearch(activeChips[0], activeChips.slice(1));
            });
        }

        updateActiveSettingsBadges();

        if (togglePriorityBtn && priorityDrawer)
            togglePriorityBtn.addEventListener('click', () => {
                const hidden = priorityDrawer.style.display === 'none' || priorityDrawer.style.display === '';
                const cnt = togglePriorityBtn.getAttribute('data-count') || '';
                const suffix = cnt ? ' (' + cnt + ')' : '';
                priorityDrawer.style.display = hidden ? 'block' : 'none';
                togglePriorityBtn.textContent = (hidden ? 'Hide fast search fields' : 'Show fast search fields') + suffix;
            });

        if (armTabs.length > 0)
            armTabs.forEach((tab) => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault();
                    const newArm = parseInt(tab.getAttribute('data-arm'), 10);
                    if (!newArm || newArm === selectedArm)
                        return;

                    selectedArm = newArm;
                    const armName = tab.getAttribute('data-arm-name') || ('Arm ' + newArm);
                    const armCount = tab.getAttribute('data-arm-count') || '0';

                    armTabs.forEach((t) => t.classList.toggle('active', t === tab));

                    if (armCountLabelEl)
                        armCountLabelEl.textContent = 'Total in Arm ' + newArm + ' (' + armName + '):';
                    if (armCountValEl)
                        armCountValEl.textContent = armCount;
                    if (createArmLabelEl)
                        createArmLabelEl.textContent = 'in Arm ' + newArm;

                    if (classicViewLink) {
                        const baseHref = classicViewLink.getAttribute('href') || '';
                        classicViewLink.setAttribute('href', baseHref.replace(/([?&]arm=)\d+/, '$1' + newArm));
                    }

                    const tabHref = tab.getAttribute('href');
                    if (tabHref && window.history && window.history.replaceState)
                        window.history.replaceState(null, '', tabHref);

                    if (activeChips.length > 0) {
                        runPrimarySearch(activeChips[0], activeChips.slice(1));
                    } else if (searchInput && searchInput.value.trim() !== '') {
                        const val = searchInput.value.trim();
                        searchInput.value = '';
                        activeChips.push(val);
                        runPrimarySearch(val, []);
                    }
                });
            });

        const showCreateError = (msg) => {
            if (!createErrorEl)
                return;
            createErrorEl.textContent = msg;
            createErrorEl.style.display = msg ? 'block' : 'none';
        };

        const stageAndNavigateNewRecord = (isAuto, recordId, btnEl) => {
            showCreateError('');
            if (btnEl)
                btnEl.disabled = true;

            const groupId = newRecordDagSelect ? newRecordDagSelect.value : '';
            module.ajax('stage_dag_assignment', {
                auto: isAuto ? 1 : 0,
                record: recordId,
                group_id: groupId,
                arm: selectedArm
            }).then((res) => {
                if (res && res.error) {
                    if (btnEl)
                        btnEl.disabled = false;
                    return showCreateError(res.error);
                }
                if (res && res.redirect_url)
                    window.location.href = res.redirect_url;
            }).catch(() => {
                if (btnEl)
                    btnEl.disabled = false;
                showCreateError('Unable to prepare new record. Please try again.');
            });
        };

        if (addRecordBtn)
            addRecordBtn.addEventListener('click', () => stageAndNavigateNewRecord(true, '', addRecordBtn));

        if (manualIdForm && manualIdInput)
            manualIdForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const val = manualIdInput.value.trim();
                if (!val)
                    return showCreateError('Please enter a record ID.');
                const submitBtn = manualIdForm.querySelector('button[type="submit"]');
                stageAndNavigateNewRecord(false, val, submitBtn);
            });

        const runPrimarySearch = (primaryQuery, additionalChips) => {
            const query = primaryQuery.trim();
            if (!query)
                return;

            activeSearchToken++;
            const myToken = activeSearchToken;
            baseRecords = [];
            currentRecords = [];
            currentPage = 1;
            activeRowIndex = -1;

            if (additionalChips && additionalChips.length > 0)
                for (let c = 0; c < additionalChips.length; c++)
                    if (activeChips.indexOf(additionalChips[c]) === -1)
                        activeChips.push(additionalChips[c]);

            renderChipBar();
            updateSearchBoxMode();
            renderRecentRecords();

            const runDeep = enableDeepCheckbox && enableDeepCheckbox.checked;
            const dagFilter = dagFilterSelect ? dagFilterSelect.value : '';

            resultsBox.style.display = 'block';
            statusBadges.innerHTML = '<span class="bs-pill bs-pill-running"><i class="fas fa-spinner fa-spin"></i> Running fast search...</span>';
            deepStatusEl.innerHTML = '';
            renderEmptyState('Running fast search...');

            fetchFastSearch(query, selectedArm, dagFilter, fuzzyThreshold).then((fastRes) => {
                if (myToken !== activeSearchToken)
                    return;

                const tier1Recs = (fastRes && fastRes.records) ? fastRes.records : [];
                const elapsedFast = (fastRes && fastRes.elapsed_ms != null) ? fastRes.elapsed_ms : 0;
                const detected = (fastRes && fastRes.detected_badges) ? fastRes.detected_badges : [];

                const excludeIds = [];
                for (let i = 0; i < tier1Recs.length; i++) {
                    const clonedRec = Object.assign({}, tier1Recs[i], {
                        initial_matches: (tier1Recs[i].matches || []).slice()
                    });
                    baseRecords.push({ rec: clonedRec, tier: 1 });
                    excludeIds.push(clonedRec.record);
                }

                applyClientFilters();
                if (currentRecords.length === 0 && baseRecords.length === 0)
                    renderEmptyState(runDeep
                        ? 'No matches in fast search. Running deep search across remaining fields...'
                        : 'No matching records found in fast search.');

                let badgesHtml = '<span class="bs-pill bs-pill-fast"><i class="fas fa-bolt"></i> Fast: '
                    + tier1Recs.length + ' ' + (tier1Recs.length === 1 ? 'match' : 'matches')
                    + ' (' + formatElapsed(elapsedFast) + ')</span>';

                for (let b = 0; b < detected.length; b++)
                    badgesHtml += '<span class="bs-pill bs-pill-detected">' + escapeHtml(detected[b]) + '</span>';

                statusBadges.innerHTML = badgesHtml;
                saveSearchStateToSession(false);

                if (!runDeep || (fastRes && fastRes.tier2_field_count === 0))
                    return;

                deepStatusEl.innerHTML = '<span class="bs-pill bs-pill-running">'
                    + '<i class="fas fa-circle-notch fa-spin"></i> Deep search (' + (fastRes.tier2_field_count || '') + ' fields)...'
                    + ' <button type="button" id="bs-cancel-deep" class="bs-link-btn" style="margin-left:5px;">Cancel</button>'
                    + '</span>';

                const cancelBtn = document.getElementById('bs-cancel-deep');
                if (cancelBtn)
                    cancelBtn.addEventListener('click', () => {
                        activeSearchToken++;
                        deepStatusEl.innerHTML = '<span class="bs-pill bs-pill-done">Deep search cancelled</span>';
                        if (currentRecords.length === 0)
                            renderEmptyState('No matching records found.');
                    });

                module.ajax('search_deep', {
                    query: query,
                    arm: selectedArm,
                    dag_filter: dagFilter,
                    fuzzy_threshold: fuzzyThreshold,
                    exclude_records: excludeIds
                }).then((deepRes) => {
                    if (myToken !== activeSearchToken)
                        return;

                    const tier2Recs = (deepRes && deepRes.records) ? deepRes.records : [];
                    const elapsedDeep = (deepRes && deepRes.elapsed_ms != null) ? deepRes.elapsed_ms : 0;

                    for (let j = 0; j < tier2Recs.length; j++) {
                        tier2Recs[j].initial_matches = (tier2Recs[j].matches || []).slice();
                        baseRecords.push({ rec: tier2Recs[j], tier: 2 });
                    }

                    applyClientFilters();
                    if (currentRecords.length === 0 && baseRecords.length === 0)
                        renderEmptyState('No matching records found across any project fields.');

                    deepStatusEl.innerHTML = '<span class="bs-pill bs-pill-done">'
                        + 'Deep: +' + tier2Recs.length + ' (' + formatElapsed(elapsedDeep) + ')</span>';
                    saveSearchStateToSession(false);
                }).catch(() => {
                    if (myToken !== activeSearchToken)
                        return;
                    deepStatusEl.innerHTML = '<span class="bs-pill bs-pill-done">Deep search incomplete</span>';
                });
            }).catch(() => {
                if (myToken !== activeSearchToken)
                    return;
                statusBadges.innerHTML = '';
                renderEmptyState('An error occurred while executing the search.');
            });
        };

        if (searchForm && searchInput) {
            searchForm.addEventListener('submit', (e) => {
                e.preventDefault();
                if (prefetchTimer) {
                    clearTimeout(prefetchTimer);
                    prefetchTimer = null;
                }
                const val = searchInput.value.trim();
                if (!val)
                    return;

                searchInput.value = '';

                if (activeChips.length === 0) {
                    activeChips.push(val);
                    runPrimarySearch(val, []);
                } else {
                    if (activeChips.indexOf(val) === -1)
                        activeChips.push(val);
                    renderChipBar();
                    updateSearchBoxMode();
                    applyClientFilters();
                }
            });

            searchInput.addEventListener('input', () => {
                const val = searchInput.value.trim();
                if (activeChips.length > 0 && baseRecords.length > 0) {
                    applyClientFilters();
                } else if (activeChips.length === 0) {
                    updateAutoMatch(val, currentRecords);
                    if (val === '') {
                        // cleared
                    } else {
                        scheduleSpeculativePrefetch();
                    }
                }
            });

            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && searchInput.value === '' && activeChips.length > 0) {
                    e.preventDefault();
                    removeChipAt(activeChips.length - 1);
                } else if (e.key === 'Enter' && e.shiftKey) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (currentExactMatch && currentExactMatch.record_url) {
                        saveRecentRecord({
                            record: currentExactMatch.record,
                            custom_label: currentExactMatch.customLabel || currentExactMatch.matchedValue,
                            record_url: currentExactMatch.record_url
                        });
                        window.location.href = currentExactMatch.record_url;
                        return;
                    }
                    const q = searchInput.value.trim();
                    if (!q)
                        return;
                    fetchFastSearch(q, selectedArm, dagFilterSelect ? dagFilterSelect.value : '', fuzzyThreshold).then((res) => {
                        if (res && res.records) {
                            const match = findExactAutoMatch(q, res.records);
                            if (match && match.record_url) {
                                saveRecentRecord({
                                    record: match.record,
                                    custom_label: match.customLabel || match.matchedValue,
                                    record_url: match.record_url
                                });
                                window.location.href = match.record_url;
                                return;
                            }
                        }
                        executeSearch(true);
                    });
                }
            });

            if (quickOpenBtn) {
                quickOpenBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (currentExactMatch && currentExactMatch.record_url) {
                        saveRecentRecord({
                            record: currentExactMatch.record,
                            custom_label: currentExactMatch.customLabel || currentExactMatch.matchedValue,
                            record_url: currentExactMatch.record_url
                        });
                        window.location.href = currentExactMatch.record_url;
                    }
                });
            }
        }

        if (dagFilterSelect)
            dagFilterSelect.addEventListener('change', () => {
                if (activeChips.length > 0)
                    runPrimarySearch(activeChips[0], activeChips.slice(1));
                else if (searchInput && searchInput.value.trim() !== '') {
                    const val = searchInput.value.trim();
                    searchInput.value = '';
                    activeChips.push(val);
                    runPrimarySearch(val, []);
                }
            });

        const updateActiveRow = (rows) => {
            for (let i = 0; i < rows.length; i++) {
                if (i === activeRowIndex) {
                    rows[i].classList.add('bs-row-active');
                    rows[i].scrollIntoView({ block: 'nearest' });
                } else {
                    rows[i].classList.remove('bs-row-active');
                }
            }
        };

        document.addEventListener('keydown', (e) => {
            if (!resultsBox || resultsBox.style.display === 'none' || currentRecords.length === 0)
                return;
            if (document.activeElement === pageSizeInput)
                return;

            const rows = resultsTbody.querySelectorAll('tr[data-record-url]');
            if (rows.length === 0)
                return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeRowIndex = Math.min(rows.length - 1, activeRowIndex + 1);
                updateActiveRow(rows);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeRowIndex = Math.max(0, activeRowIndex - 1);
                updateActiveRow(rows);
            } else if (e.key === 'Enter' && activeRowIndex >= 0 && document.activeElement !== searchInput && document.activeElement !== manualIdInput) {
                e.preventDefault();
                const url = rows[activeRowIndex].getAttribute('data-record-url');
                if (url) {
                    const startIdx = (currentPage - 1) * pageSize;
                    const item = currentRecords[startIdx + activeRowIndex];
                    if (item && item.rec)
                        saveRecentRecord(item.rec);
                    saveSearchStateToSession(true);
                    window.location.href = url;
                }
            }
        });

        const tryRestoreSearchStateFromSession = () => {
            try {
                const raw = sessionStorage.getItem(sessionStateKey);
                if (!raw)
                    return false;

                const saved = JSON.parse(raw);
                if (!saved || !Array.isArray(saved.activeChips) || saved.activeChips.length === 0)
                    return false;

                const navEntries = (window.performance && window.performance.getEntriesByType)
                    ? window.performance.getEntriesByType('navigation')
                    : [];
                const isBackForward = (navEntries.length > 0 && navEntries[0].type === 'back_forward')
                    || (window.performance && window.performance.navigation && window.performance.navigation.type === 2);

                if (!isBackForward && !saved.leavingForRecord)
                    return false;

                if ((Date.now() - (saved.ts || 0)) > 1800000) {
                    sessionStorage.removeItem(sessionStateKey);
                    return false;
                }

                activeChips = saved.activeChips;
                baseRecords = Array.isArray(saved.baseRecords) ? saved.baseRecords : [];
                if (dagFilterSelect && saved.dagFilter !== undefined)
                    dagFilterSelect.value = saved.dagFilter;

                renderChipBar();
                updateSearchBoxMode();
                renderRecentRecords();

                if (resultsBox)
                    resultsBox.style.display = 'block';
                if (statusBadges && saved.statusBadgesHtml)
                    statusBadges.innerHTML = saved.statusBadgesHtml;
                if (deepStatusEl && saved.deepStatusHtml)
                    deepStatusEl.innerHTML = saved.deepStatusHtml;

                applyClientFilters();
                if (saved.currentPage && saved.currentPage > 1) {
                    currentPage = saved.currentPage;
                    renderCurrentPage();
                }

                saveSearchStateToSession(false);
                return true;
            } catch (e) {
                return false;
            }
        };

        if (!tryRestoreSearchStateFromSession())
            renderRecentRecords();

        if (searchInput && searchInput.value.trim() && Object.keys(locatorMap).length > 0)
            updateAutoMatch(searchInput.value.trim(), currentRecords);

        scheduleIdleTask(startLocatorSync, 800);
    });
})();
