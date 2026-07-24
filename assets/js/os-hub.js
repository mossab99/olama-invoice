/**
 * os-hub.js — Customer Hub Panel Manager
 *
 * Phase 1: PanelManager + keyboard shortcuts + type card interactions
 * Phase 2: SearchModule, CountsLoader, TileManager AJAX, IdentityHeader
 * Phase 3: ProfileEditor (inline edit + activate/deactivate)
 * Phase 4: YearSelector, QuickActions, TileShortcuts, ChildrenInlineAdd
 *
 * Pattern: IIFE module (matches existing olama-reg.js style)
 * Data: JSON hydration block via #os-hub-data (per OLAMASKILL.md §4.1)
 * NO wp_localize_script usage here.
 */
/* global jQuery */
(function ($) {
    'use strict';

    // ── Bootstrap: Read JSON hydration block ──────────────────────────────────
    var hubDataEl = document.getElementById('os-hub-data');
    if (!hubDataEl) {
        // Not on hub page — do nothing
        return;
    }

    var HUB_DATA  = JSON.parse(hubDataEl.textContent);
    var AJAX_URL  = HUB_DATA.ajaxUrl;
    var NONCE     = HUB_DATA.nonce;
    var REG_NONCE = HUB_DATA.regNonce;
    var USER_ID   = HUB_DATA.currentUserId;
    var I18N      = HUB_DATA.i18n;

    // localStorage key scoped per user (shared workstation safety)
    var RECENT_KEY = 'os_hub_recent_' + USER_ID;

    // ── State ─────────────────────────────────────────────────────────────────
    var state = {
        currentType:     null,   // 'family' | 'external'
        currentCustomer: null,   // { uid, name, type, phone, count }
        currentPanel:    'type', // 'type' | 'lookup' | 'hub'
        openTile:        null,   // tile id currently expanded
        currentYearId:   HUB_DATA.currentYearId || 0,  // active academic year filter
        tileRequestSeq:  0,
        countRequestSeq: 0,
    };

    // ══════════════════════════════════════════════════════════════════════════
    // PanelManager — controls which of the 3 stage panels is visible
    // ══════════════════════════════════════════════════════════════════════════
    var PanelManager = {

        panels: {
            type:   '#os-hub-panel-type',
            lookup: '#os-hub-panel-lookup',
            hub:    '#os-hub-panel-hub',
        },

        /**
         * Show the specified panel, hide all others.
         * @param {string} panelId  'type' | 'lookup' | 'hub'
         */
        show: function (panelId) {
            var self = this;
            Object.keys(self.panels).forEach(function (id) {
                var $el = $(self.panels[id]);
                if (id === panelId) {
                    $el.addClass('os-hub-panel--active')
                       .removeAttr('aria-hidden');
                    // Restore aria-hidden = false explicitly
                    $el.attr('aria-hidden', 'false');
                } else {
                    $el.removeClass('os-hub-panel--active')
                       .attr('aria-hidden', 'true');
                }
            });
            state.currentPanel = panelId;

            // Focus management: move focus to first focusable element in panel
            var $panel = $(self.panels[panelId]);
            var $focusable = $panel.find('input, button, [tabindex="0"]').filter(':visible').first();
            if ($focusable.length) {
                setTimeout(function () { $focusable.focus(); }, 100);
            }
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // TypeSelector — Stage 1: Family vs External card interactions
    // ══════════════════════════════════════════════════════════════════════════
    var TypeSelector = {

        init: function () {
            var self = this;

            // Click on type cards
            $(document).on('click', '.os-hub-type-card', function () {
                var type = $(this).data('type');
                self.select(type);
            });

            // Keyboard: Enter / Space activates the card
            $(document).on('keydown', '.os-hub-type-card', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    var type = $(this).data('type');
                    self.select(type);
                }
            });

            // Global keyboard shortcuts (only when on type panel)
            $(document).on('keydown', function (e) {
                // Only fire if no input/textarea is focused and we're on type panel
                if ($(e.target).is('input, textarea, select, [contenteditable]')) return;
                if (state.currentPanel !== 'type') return;

                if (e.key === 'f' || e.key === 'F') {
                    e.preventDefault();
                    self.select('family');
                } else if (e.key === 'e' || e.key === 'E') {
                    e.preventDefault();
                    self.select('external');
                }
            });
        },

        select: function (type) {
            state.currentType = type;

            // Update aria-pressed on cards
            $('.os-hub-type-card').attr('aria-pressed', 'false');
            $('.os-hub-type-card[data-type="' + type + '"]').attr('aria-pressed', 'true');

            // Update type badge in lookup panel
            TypeBadge.update(type);

            // Show the relevant creation shortcut for the selected contact type.
            if (type === 'family') {
                $('#family_btn_add_new').css('display', 'flex');
                $('#cust_btn_add_new').hide();
            } else if (type === 'external') {
                $('#family_btn_add_new').hide();
                $('#cust_btn_add_new').css('display', 'flex');
            } else {
                $('#family_btn_add_new').hide();
                $('#cust_btn_add_new').hide();
            }

            // Transition to lookup panel
            PanelManager.show('lookup');

            // Phase 2: SearchModule will init here
            SearchModule.reset();
            RecentLookups.render(type);
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // TypeBadge — small pill showing current type in lookup panel
    // ══════════════════════════════════════════════════════════════════════════
    var TypeBadge = {
        update: function (type) {
            var $badge = $('#os-hub-current-type-badge');
            var label  = (type === 'family') ? I18N.family : I18N.external;
            var cls    = (type === 'family') ? '' : 'os-hub-type-badge--external';

            $badge
                .text(label)
                .removeClass('os-hub-type-badge--external')
                .addClass(cls)
                .prepend(
                    $('<span class="dashicons" aria-hidden="true">').addClass(
                        type === 'family' ? 'dashicons-groups' : 'dashicons-admin-users'
                    )
                );
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // RecentLookups — localStorage-based recent customer list
    // ══════════════════════════════════════════════════════════════════════════
    var RecentLookups = {

        get: function () {
            try {
                return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
            } catch (e) {
                return [];
            }
        },

        save: function (customer) {
            var recent   = this.get();
            var filtered = recent.filter(function (r) { return r.uid !== customer.uid; });
            filtered.unshift({
                uid:       customer.uid,
                name:      customer.name,
                type:      customer.type,
                phone:     customer.phone || '',
                timestamp: Date.now(),
            });
            try {
                localStorage.setItem(RECENT_KEY, JSON.stringify(filtered.slice(0, 10)));
            } catch (e) { /* storage full — ignore */ }
        },

        remove: function (uid) {
            var recent   = this.get();
            var filtered = recent.filter(function (r) { return r.uid !== uid; });
            try {
                localStorage.setItem(RECENT_KEY, JSON.stringify(filtered));
            } catch (e) { }
        },

        render: function (filterType) {
            var recent  = this.get();
            var $list   = $('#os-hub-recent-list');
            var $section = $('#os-hub-recent-section');

            // Filter by current type
            var filtered = recent.filter(function (r) { return r.type === filterType; });

            if (!filtered.length) {
                $section.hide();
                return;
            }

            $section.show();
            $list.empty();

            filtered.forEach(function (r) {
                var $item = $('<li class="os-hub-recent__item" role="option" tabindex="0">')
                    .data('customer', r)
                    .attr('aria-label', r.name);

                $item.append(
                    $('<span class="dashicons dashicons-backup" aria-hidden="true">'),
                    $('<span class="os-hub-recent__item-name">').text(r.name),
                    $('<span class="os-hub-recent__item-type">').text(
                        r.type === 'family' ? I18N.family : I18N.external
                    )
                );

                $list.append($item);
            });
        },

        init: function () {
            // Click on recent item
            $(document).on('click keydown', '#os-hub-recent-list .os-hub-recent__item', function (e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                var customer = $(this).data('customer');
                CustomerHub.loadCustomer(customer);
            });
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // SearchModule — Phase 2 placeholder (wired up next phase)
    // ══════════════════════════════════════════════════════════════════════════
    var FamilyCreateModal = {
        open: function () {
            var form = document.getElementById('family-modal-form');
            if (form) form.reset();
            this.notice('');
            $('#family-modal-overlay').css('display', 'flex');
            setTimeout(function () { $('#family-modal-name').focus(); }, 80);
        },

        close: function () {
            $('#family-modal-overlay').hide();
        },

        notice: function (message, isError) {
            var $notice = $('#family-modal-notice');
            if (!message) {
                $notice.hide().text('');
                return;
            }

            $notice
                .text(message)
                .css({
                    display: 'block',
                    background: isError ? '#fee2e2' : '#dcfce7',
                    color: isError ? '#991b1b' : '#166534',
                    border: '1px solid ' + (isError ? '#fecaca' : '#bbf7d0')
                });
        },

        init: function () {
            var self = this;

            $(document).on('click', '#family-modal-close, #family-modal-cancel', function () {
                self.close();
            });

            $(document).on('click', '#family-modal-overlay', function (e) {
                if (e.target === this) self.close();
            });

            $(document).on('submit', '#family-modal-form', function (e) {
                e.preventDefault();

                var $form = $(this);
                var $btn = $('#family-modal-save');
                var familyName = $('#family-modal-name').val().trim();
                if (!familyName) {
                    $('#family-modal-name').focus();
                    self.notice('اسم العائلة مطلوب.', true);
                    return;
                }

                $btn.prop('disabled', true).text('جاري الحفظ...');

                $.post(AJAX_URL, {
                    action: 'os_hub_add_family',
                    nonce: NONCE,
                    family_uid: $('#family-modal-uid').val().trim(),
                    family_name: familyName,
                    father_mobile: $('#family-modal-father-mobile').val().trim(),
                    mother_mobile: $('#family-modal-mother-mobile').val().trim(),
                    address: $('#family-modal-address').val().trim()
                }).done(function (response) {
                    if (!response || !response.success) {
                        self.notice((response && response.data && response.data.message) || I18N.errorGeneric, true);
                        return;
                    }

                    self.notice(response.data.message || 'تم حفظ العائلة.', false);
                    self.close();
                    CustomerHub.loadCustomer(response.data.customer);
                    $form[0].reset();
                }).fail(function () {
                    self.notice(I18N.networkError || I18N.errorGeneric, true);
                }).always(function () {
                    $btn.prop('disabled', false).text('حفظ العائلة');
                });
            });
        }
    };

    var SearchModule = {

        _timer: null,

        reset: function () {
            $('#os-hub-search-input').val('');
            $('#os-hub-results-list').empty();
            $('#os-hub-search-status').empty();
        },

        init: function () {
            // Debounced search on input
            $(document).on('input', '#os-hub-search-input', function () {
                var query = $(this).val().trim();
                clearTimeout(SearchModule._timer);
                if (query.length < 2) {
                    $('#os-hub-results-list').empty();
                    $('#os-hub-search-status').empty();
                    return;
                }
                SearchModule._timer = setTimeout(function () {
                    SearchModule.run(query);
                }, 200);
            });

            // Search button click
            $(document).on('click', '#os-hub-search-btn', function () {
                var query = $('#os-hub-search-input').val().trim();
                if (query.length >= 2) {
                    SearchModule.run(query);
                }
            });

            // Enter key in search input
            $(document).on('keydown', '#os-hub-search-input', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var query = $(this).val().trim();
                    if (query.length >= 2) {
                        SearchModule.run(query);
                    } else {
                        // If results showing, select first
                        var $first = $('#os-hub-results-list li:first');
                        if ($first.length) $first.trigger('click');
                    }
                }
                // ↓ Arrow down: move to first result
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    $('#os-hub-results-list li:first').focus();
                }
            });

            // Keyboard navigation inside results list
            $(document).on('keydown', '#os-hub-results-list li', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    $(this).next('li').focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var $prev = $(this).prev('li');
                    if ($prev.length) {
                        $prev.focus();
                    } else {
                        $('#os-hub-search-input').focus();
                    }
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });

            // Click result
            $(document).on('click', '#os-hub-results-list .os-hub-result-item', function () {
                var customer = $(this).data('customer');
                CustomerHub.loadCustomer(customer);
            });

            // Route the generic empty-state button to the active contact type.
            $(document).on('click', '#cust_btn_add_new, #os-hub-add-new-btn', function (e) {
                if (this.id === 'os-hub-add-new-btn' && state.currentType === 'family') {
                    e.preventDefault();
                    $('#family_btn_add_new').trigger('click');
                    return;
                }

                if (state.currentType !== 'external') {
                    e.preventDefault();
                    return;
                }

                if (this.id === 'os-hub-add-new-btn') {
                    e.preventDefault();
                    $('#cust_btn_add_new').trigger('click');
                }
            });

            $(document).on('click', '#family_btn_add_new', function () {
                FamilyCreateModal.open();
            });
        },

        run: function (query) {
            var $status  = $('#os-hub-search-status');
            var $results = $('#os-hub-results-list');

            $status.html(
                '<span class="os-hub-spinner" aria-hidden="true"></span>' +
                '<span>' + I18N.loading + '</span>'
            );
            $results.empty();

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data: {
                    action: 'os_hub_search',
                    nonce:  NONCE,
                    q:      query,
                    type:   state.currentType,
                },
                success: function (response) {
                    $status.empty();
                    if (!response.success) {
                        SearchModule.showError(I18N.errorGeneric);
                        return;
                    }
                    var results = response.data.results || [];
                    if (!results.length) {
                        SearchModule.showEmpty(query);
                        return;
                    }
                    SearchModule.renderResults(results);
                },
                error: function () {
                    $status.empty();
                    SearchModule.showError(I18N.networkError);
                },
            });
        },

        renderResults: function (results) {
            var $list = $('#os-hub-results-list');
            $list.empty();

            results.forEach(function (r) {
                var isFamily   = (state.currentType === 'family');
                var countLabel = isFamily
                    ? (r.student_count + ' ' + I18N.students)
                    : (r.child_count   + ' ' + I18N.children);

                var $item = $('<li>')
                    .addClass('os-hub-result-item')
                    .addClass(isFamily ? '' : 'os-hub-result-item--external')
                    .attr({ role: 'option', tabindex: '0', 'aria-selected': 'false' })
                    .data('customer', {
                        uid:         r.uid,
                        name:        r.name,
                        type:        state.currentType,
                        phone:       r.phone || '',
                        is_active:   (r.is_active !== undefined) ? parseInt(r.is_active, 10) : 1,
                        internal_id: r.internal_id || '',
                    });

                $item.append(
                    $('<span class="os-hub-result-item__icon" aria-hidden="true">').append(
                        $('<span class="dashicons">').addClass(
                            isFamily ? 'dashicons-groups' : 'dashicons-admin-users'
                        )
                    ),
                    $('<div class="os-hub-result-item__body">').append(
                        $('<span class="os-hub-result-item__name">').text(r.name),
                        $('<span class="os-hub-result-item__meta">').text(r.phone + ' · ' + countLabel)
                    ),
                    $('<span class="os-hub-result-item__uid os-hub-ltr">').text(r.uid)
                );

                $list.append($item);
            });
        },

        showEmpty: function (query) {
            var addAction = state.currentType === 'external'
                ? '<div class="os-hub-notice__actions">' +
                  '<button type="button" class="button" id="os-hub-add-new-btn">' +
                  I18N.addNewCustomer + '</button></div>'
                : '';
            $('#os-hub-results-list').html(
                '<li class="os-hub-notice" style="padding:20px 16px;">' +
                '<span class="dashicons dashicons-warning" aria-hidden="true"></span>' +
                '<span class="os-hub-notice__title">' + I18N.noResults + '</span>' +
                '<span class="os-hub-notice__body">' + escHtml(query) + '</span>' +
                addAction +
                '</li>'
            );
        },

        showError: function (msg) {
            $('#os-hub-search-status').html(
                '<span class="dashicons dashicons-warning" style="color:#d63638;" aria-hidden="true"></span>' +
                '<span style="color:#d63638;">' + escHtml(msg) + '</span>'
            );
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // TileManager — accordion expand/collapse + AJAX content loading
    // ══════════════════════════════════════════════════════════════════════════
    var TileManager = {

        init: function () {
            // Tile button click → toggle accordion
            $(document).on('click', '.os-hub-tile', function () {
                var tileId  = $(this).data('tile');
                var $btn    = $(this);
                var $panel  = $('#os-hub-tile-panel-' + tileId);
                var isOpen  = $btn.attr('aria-expanded') === 'true';

                // Close all tiles first
                TileManager.collapseAll();

                if (!isOpen) {
                    TileManager.open(tileId, $btn, $panel);
                }
            });

            // Keyboard on tile button
            $(document).on('keydown', '.os-hub-tile', function (e) {
                if (e.key === 'Escape') {
                    TileManager.collapseAll();
                    $(this).focus();
                }
            });
        },

        open: function (tileId, $btn, $panel, extraData) {
            $btn.attr('aria-expanded', 'true');
            $panel.attr('aria-hidden', 'false').show();
            state.openTile = tileId;

            var $content = $panel.find('.os-hub-tile-panel__content');
            var $loading = $panel.find('.os-hub-tile-panel__loading');

            // Only load if not already loaded for this customer (skip cache if extraData is passed)
            if ($content.data('loaded') && !extraData) {
                $loading.hide();
                $content.show();
                // Still fire the event so TileReloader can add its button
                $(document).trigger('os-hub:tile-opened', [{ tileId: tileId, $panel: $panel }]);
                return;
            }

            $loading.show();
            $content.hide(); // Don't empty to prevent layout jumps if reloading with filters

            if (!state.currentCustomer) return;

            var requestSeq = ++state.tileRequestSeq;
            var requestUid = state.currentCustomer.uid;
            var requestType = state.currentCustomer.type;

            var ajaxData = $.extend({
                action: 'os_hub_tile',
                nonce:  NONCE,
                tile:   tileId,
                uid:    requestUid,
                type:   requestType,
                year:   state.currentYearId,
            }, extraData || {});

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data:   ajaxData,
                success: function (response) {
                    if (
                        requestSeq !== state.tileRequestSeq ||
                        !state.currentCustomer ||
                        state.currentCustomer.uid !== requestUid ||
                        state.currentCustomer.type !== requestType ||
                        state.openTile !== tileId
                    ) {
                        return;
                    }

                    $loading.hide();
                    $content.show();

                    if (!response.success) {
                        $content.html(
                            '<div class="os-hub-notice os-hub-notice--error">'
                            + '<span class="dashicons dashicons-warning" aria-hidden="true"></span>'
                            + '<p class="os-hub-notice__title">' + escHtml(I18N.errorGeneric) + '</p>'
                            + '</div>'
                        );
                        return;
                    }

                    $content.html(response.data.html);
                    if (!extraData) {
                        $content.data('loaded', true);
                    }

                    // Fire event so TileReloader injects its button
                    $(document).trigger('os-hub:tile-opened', [{ tileId: tileId, $panel: $panel }]);
                },
                error: function () {
                    if (
                        requestSeq !== state.tileRequestSeq ||
                        !state.currentCustomer ||
                        state.currentCustomer.uid !== requestUid ||
                        state.currentCustomer.type !== requestType ||
                        state.openTile !== tileId
                    ) {
                        return;
                    }

                    $loading.hide();
                    $content.show().html(
                        '<div class="os-hub-notice os-hub-notice--error">'
                        + '<span class="dashicons dashicons-warning" aria-hidden="true"></span>'
                        + '<p class="os-hub-notice__title">' + escHtml(I18N.networkError) + '</p>'
                        + '<div class="os-hub-notice__actions">'
                        + '<button type="button" class="button os-hub-tile-retry" data-tile="' + tileId + '">' + escHtml(I18N.retry) + '</button>'
                        + '</div></div>'
                    );
                },
            });
        },

        refresh: function (tileId, extraData) {
            if (!tileId) return;

            var $btn = $('#os-hub-tile-btn-' + tileId);
            var $panel = $('#os-hub-tile-panel-' + tileId);
            var $content = $panel.find('.os-hub-tile-panel__content');

            if (!$btn.length || !$panel.length) return;

            $content.removeData('loaded');
            this.open(tileId, $btn, $panel, extraData || { refresh: 1 });
        },

        refreshCurrent: function () {
            if (state.openTile) {
                this.refresh(state.openTile);
            }
        },

        collapseAll: function () {
            $('.os-hub-tile').attr('aria-expanded', 'false');
            $('.os-hub-tile-panel').attr('aria-hidden', 'true').hide();
            state.openTile = null;
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // CountsLoader — loads badge counts for all tiles in one AJAX request
    // ══════════════════════════════════════════════════════════════════════════
    var CountsLoader = {

        load: function (uid, type) {
            var requestSeq = ++state.countRequestSeq;

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data: {
                    action: 'os_hub_counts',
                    nonce:  NONCE,
                    uid:    uid,
                    type:   type,
                    year:   state.currentYearId,
                },
                success: function (response) {
                    if (
                        requestSeq !== state.countRequestSeq ||
                        !state.currentCustomer ||
                        state.currentCustomer.uid !== uid ||
                        state.currentCustomer.type !== type
                    ) {
                        return;
                    }

                    if (!response.success) {
                        if (response.data && response.data.code === 'not_found') {
                            alert(response.data.message || 'العميل المحدّد لم يعد موجوداً في النظام.');
                            // Remove from recent lookups list
                            RecentLookups.remove(uid);
                            // Navigate back to search/lookup stage
                            $('#os-hub-back-to-search').trigger('click');
                        }
                        return;
                    }

                    var counts = response.data.counts || {};
                    var fin    = response.data.financial_mini || null;

                    // Update tile badges
                    Object.keys(counts).forEach(function (tile) {
                        var count  = counts[tile];
                        var $badge = $('#os-hub-tile-badge-' + tile);
                        if (count !== null && count !== undefined && count > 0) {
                            $badge.text(count).show();
                        } else {
                            $badge.hide();
                        }
                    });

                    // Update identity header with financial mini-stats / family card (families only)
                    if (type === 'family') {
                        var fcData = response.data.family_financial_card || null;
                        if (fcData) {
                            IdentityHeader.renderFamilyCard(fcData);
                        } else if (fin) {
                            IdentityHeader.renderFinStats(fin);
                        }
                    }
                },
                error: function () {
                    // Silently fail — badge counts are non-critical
                },
            });
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // CustomerHub — top-level: loads customer into the hub (Stage 3)
    // ══════════════════════════════════════════════════════════════════════════
    function refreshDashboardTiles(tileIds) {
        if (!state.currentCustomer) return;

        CountsLoader.load(state.currentCustomer.uid, state.currentCustomer.type);

        if (Array.isArray(tileIds) && tileIds.length) {
            if (state.openTile && tileIds.indexOf(state.openTile) !== -1) {
                TileManager.refresh(state.openTile);
            }
            return;
        }

        TileManager.refreshCurrent();
    }

    function hubNotice(message, type) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        var $target = $('.os-hub-wrap').first();
        var $notice = $('#os-hub-action-notice');

        if (!$notice.length) {
            $notice = $('<div id="os-hub-action-notice" class="notice inline" style="margin:12px 0; padding:10px 12px;"></div>');
            $target.prepend($notice);
        }

        $notice
            .removeClass('notice-success notice-error')
            .addClass(cls)
            .html('<p style="margin:0;">' + escHtml(message || I18N.errorGeneric) + '</p>')
            .stop(true, true)
            .fadeIn(120)
            .delay(3500)
            .fadeOut(250);
    }

    var CustomerHub = {

        loadCustomer: function (customer) {
            state.currentCustomer = customer;

            // Save to recent lookups
            RecentLookups.save(customer);

            // Update identity header
            if (customer.type === 'family') {
                $('#os-hub-identity-standard').hide();
                $('#os-hub-identity-family').show();
                // Prepopulate basic details
                $('#os-fc-family-uid').text(customer.uid);
                $('#os-fc-family-name').text(customer.name);
            } else {
                $('#os-hub-identity-family').hide();
                $('#os-hub-identity-standard').css('display', 'flex'); // maintain flex layout
                IdentityHeader.render(customer);
            }

            // Settlement tile visibility (families only)
            if (customer.type === 'family') {
                $('.os-hub-tile-item--family-only').removeClass('os-hub-tile-item--hidden');
            } else {
                $('.os-hub-tile-item--family-only').addClass('os-hub-tile-item--hidden');
            }

            // Reset tiles (clear loaded content for fresh customer)
            TileManager.collapseAll();
            $('.os-hub-tile-panel__content').removeData('loaded').empty();

            // Show hub panel
            PanelManager.show('hub');

            // Load badge counts via AJAX
            CountsLoader.load(customer.uid, customer.type);

            // Phase 4: show quick actions + year selector
            QuickActions.show(customer);
            YearSelector.showForCustomer();

            // Update URL dynamically to persist active customer state
            if (history.replaceState) {
                var param = (customer.type === 'family') ? 'family_uid' : 'customer_uid';
                var newUrl = window.location.pathname + '?page=olama-registration&' + param + '=' + customer.uid;
                history.replaceState(null, '', newUrl);
            }
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // IdentityHeader — renders the sticky customer info bar in Stage 3
    // ══════════════════════════════════════════════════════════════════════════
    var IdentityHeader = {
        render: function (customer) {
            $('#os-hub-identity-name').text(customer.name);

            // Status dot
            var isActive  = (customer.is_active === undefined) ? true : (customer.is_active == 1);
            var statusDot = isActive
                ? '<span class="os-hub-status-dot os-hub-status-dot--active" title="' + escHtml(I18N.active || 'نشط') + '" aria-label="' + escHtml(I18N.active || 'نشط') + '"></span>'
                : '<span class="os-hub-status-dot os-hub-status-dot--inactive" title="' + escHtml(I18N.inactive || 'غير نشط') + '" aria-label="' + escHtml(I18N.inactive || 'غير نشط') + '"></span>';

            var meta = statusDot;
            meta += '<span class="os-hub-uid-copy" title="نسخ" data-uid="' + escHtml(customer.uid) + '">'
                  + '<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>'
                  + escHtml(customer.uid) + '</span>';
            if (customer.phone) meta += '<span>·</span><span>' + escHtml(customer.phone) + '</span>';
            var typeLbl = customer.type === 'family' ? I18N.family : I18N.external;
            meta += '<span class="os-hub-type-badge' + (customer.type === 'external' ? ' os-hub-type-badge--external' : '') + '">' + escHtml(typeLbl) + '</span>';
            $('#os-hub-identity-meta').html(meta);

            // Avatar icon
            var $avatar = $('#os-hub-identity .os-hub-identity__avatar');
            $avatar.find('.dashicons')
                   .removeClass('dashicons-admin-users dashicons-groups')
                   .addClass(customer.type === 'family' ? 'dashicons-groups' : 'dashicons-admin-users');

            // Clear previous fin-stats
            $('#os-hub-identity-fin-stats').remove();
        },

        renderFinStats: function (fin) {
            var $existing = $('#os-hub-identity-fin-stats');
            if ($existing.length) $existing.remove();

            if (!fin) return;

            var fmt = function (n) { return parseFloat(n).toLocaleString('ar', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

            var html = '<div class="os-hub-identity__fin-stats" id="os-hub-identity-fin-stats">';
            html += '<span class="os-hub-identity__fin-stat">'
                 + '<span>مفوتر: </span><strong dir="ltr">' + fmt(fin.total_billed) + '</strong></span>';
            html += '<span class="os-hub-identity__fin-stat os-hub-identity__fin-stat--paid">'
                 + '<span>مدفوع: </span><strong dir="ltr">' + fmt(fin.total_paid) + '</strong></span>';
            if (parseFloat(fin.total_balance) > 0) {
                html += '<span class="os-hub-identity__fin-stat os-hub-identity__fin-stat--balance">'
                     + '<span>رصيد: </span><strong dir="ltr">' + fmt(fin.total_balance) + '</strong></span>';
            }
            html += '</div>';

            $('#os-hub-identity-meta').after(html);
        },

        renderFamilyCard: function (card) {
            var fmt = function (n) { 
                return parseFloat(n).toLocaleString('ar', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' د.أ'; 
            };
            
            $('#os-fc-family-uid').text(card.family_uid);
            $('#os-fc-students').text(card.students).attr('title', card.students);
            $('#os-fc-total-fees').text(fmt(card.total_fees));
            $('#os-fc-gross-invoiced').text(fmt(card.total_billed));
            $('#os-fc-total-paid').text(fmt(card.total_paid));
            
            var balanceEl = $('#os-fc-remaining-balance');
            balanceEl.text(fmt(card.total_balance));
            if (parseFloat(card.total_balance) > 0.009) {
                balanceEl.css('color', '#c82333');
            } else {
                balanceEl.css('color', '#1a7c2e');
            }

            // Update Academic Year text based on selected ID
            var selectedYearName = '';
            var yearId = state.currentYearId;
            if (yearId === 0) {
                selectedYearName = I18N.yearAll || 'جميع السنوات';
            } else if (HUB_DATA.academicYears) {
                var yr = HUB_DATA.academicYears.find(function(y) { return y.id === yearId; });
                if (yr) selectedYearName = yr.name;
            }
            $('#os-fc-academic-year').text(selectedYearName);
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // Navigation — Back buttons
    // ══════════════════════════════════════════════════════════════════════════
    var Navigation = {
        init: function () {
            // Back to type selection
            $(document).on('click', '#os-hub-back-to-type', function () {
                state.currentType     = null;
                state.currentCustomer = null;
                $('#family_btn_add_new').hide();
                $('#cust_btn_add_new').hide();
                PanelManager.show('type');

                // Clear URL dynamically to reset active customer state
                if (history.replaceState) {
                    var cleanUrl = window.location.pathname + '?page=olama-registration';
                    history.replaceState(null, '', cleanUrl);
                }
            });

            // Back to search
            $(document).on('click', '#os-hub-back-to-search', function () {
                TileManager.collapseAll();
                if (state.currentType === 'family') {
                    $('#family_btn_add_new').css('display', 'flex');
                    $('#cust_btn_add_new').hide();
                } else if (state.currentType === 'external') {
                    $('#family_btn_add_new').hide();
                    $('#cust_btn_add_new').css('display', 'flex');
                } else {
                    $('#family_btn_add_new').hide();
                    $('#cust_btn_add_new').hide();
                }
                PanelManager.show('lookup');
                // Re-render recent for current type
                RecentLookups.render(state.currentType);

                // Clear URL dynamically to reset active customer state
                if (history.replaceState) {
                    var cleanUrl = window.location.pathname + '?page=olama-registration';
                    history.replaceState(null, '', cleanUrl);
                }
            });

            // Tile retry button
            $(document).on('click', '.os-hub-tile-retry', function () {
                var tileId = $(this).data('tile');
                var $btn   = $('#os-hub-tile-btn-' + tileId);
                var $panel = $('#os-hub-tile-panel-' + tileId);
                $panel.find('.os-hub-tile-panel__content').removeData('loaded').empty();
                TileManager.open(tileId, $btn, $panel);
            });

            // Global Escape key
            $(document).on('keydown', function (e) {
                if ($(e.target).is('input, textarea, select')) return;
                if (e.key !== 'Escape') return;

                if (state.currentPanel === 'hub') {
                    if (state.openTile) {
                        TileManager.collapseAll();
                    } else {
                        PanelManager.show('lookup');
                        RecentLookups.render(state.currentType);
                    }
                } else if (state.currentPanel === 'lookup') {
                    state.currentType = null;
                    PanelManager.show('type');
                }
            });

            // Ctrl+K: focus search input
            $(document).on('keydown', function (e) {
                if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    if (state.currentPanel === 'lookup') {
                        $('#os-hub-search-input').focus();
                    }
                }
            });
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // PHASE 5 MODULES
    // ══════════════════════════════════════════════════════════════════════════

    // ── TileReloader: ↺ button inside each open tile panel ──────────────────
    var TileReloader = {
        init: function () {
            // Inject reload button when a tile opens
            $(document).on('os-hub:tile-opened', function (e, data) {
                var $panel = data.$panel;
                var tileId = data.tileId;
                // Remove any existing reload button first
                $panel.find('.os-hub-tile-reload-btn').remove();

                var $btn = $('<button type="button">')
                    .addClass('os-hub-tile-reload-btn')
                    .attr({
                        'aria-label': 'تحديث',
                        'title':       'تحديث البيانات',
                        'data-tile':   tileId,
                    })
                    .html('<span class="dashicons dashicons-update" aria-hidden="true"></span>');

                $panel.find('.os-hub-tile-panel__inner').prepend($btn);
            });

            // Click reload button
            $(document).on('click', '.os-hub-tile-reload-btn', function () {
                var tileId = $(this).data('tile');
                var $btn   = $('#os-hub-tile-btn-' + tileId);
                var $panel = $('#os-hub-tile-panel-' + tileId);
                $panel.find('.os-hub-tile-panel__content').removeData('loaded').empty();
                TileManager.open(tileId, $btn, $panel);
            });
        },
    };

    // ── UidCopy: copy UID to clipboard from identity meta bar ─────────────
    var UidCopy = {
        init: function () {
            $(document).on('click', '.os-hub-uid-copy', function () {
                var uid  = $(this).data('uid');
                var self = this;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(uid).then(function () {
                        UidCopy.flash(self, '✓ تم النسخ');
                    });
                } else {
                    // Fallback
                    var $t = $('<textarea>').val(uid).appendTo('body').select();
                    document.execCommand('copy');
                    $t.remove();
                    UidCopy.flash(self, '✓ تم النسخ');
                }
            });
        },

        flash: function (el, msg) {
            var $el  = $(el);
            var orig = $el.html();
            $el.html('<span class="dashicons dashicons-yes" aria-hidden="true"></span>' + msg);
            setTimeout(function () { $el.html(orig); }, 1500);
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // PHASE 4 MODULES
    // ══════════════════════════════════════════════════════════════════════════

    // ── YearSelector: populate & respond to academic year dropdown ─────────
    var YearSelector = {

        _populated: false,

        init: function () {
            var self = this;

            // Populate the <select> once from HUB_DATA
            if (!self._populated && HUB_DATA.academicYears && HUB_DATA.academicYears.length) {
                var $sel = $('#os-hub-year-select');
                // "All years" option
                $sel.append($('<option>').val('0').text(I18N.yearAll || 'جميع السنوات'));
                HUB_DATA.academicYears.forEach(function (y) {
                    var $opt = $('<option>').val(y.id).text(y.name);
                    if (y.id === HUB_DATA.currentYearId) $opt.prop('selected', true);
                    $sel.append($opt);
                });
                self._populated = true;
            }

            // On year change: update state, bust tile caches, reload counts
            $(document).on('change', '#os-hub-year-select', function () {
                state.currentYearId = parseInt($(this).val(), 10) || 0;
                // Bust all tile caches for this customer
                $('.os-hub-tile-panel__content').removeData('loaded').empty();
                TileManager.collapseAll();
                if (state.currentCustomer) {
                    CountsLoader.load(state.currentCustomer.uid, state.currentCustomer.type);
                }
            });
        },

        showForCustomer: function () {
            if (HUB_DATA.academicYears && HUB_DATA.academicYears.length) {
                $('#os-hub-year-wrap').show();
            }
        },

        hide: function () {
            $('#os-hub-year-wrap').hide();
        },
    };

    // ── QuickActions: quick-link bar in identity header ───────────────────
    var ADMIN_URL = HUB_DATA.adminUrl || HUB_DATA.ajaxUrl.replace('admin-ajax.php', '');

    var OriginalFormModal = {
        open: function (form, title, extraData) {
            var customer = state.currentCustomer;
            if (!customer) return;

            var $modal = $('#os-hub-original-form-modal');
            var $content = $('#os-hub-original-form-content');

            $('#os-hub-original-form-title').html(
                '<span class="dashicons dashicons-media-text"></span> ' + title
            );
            $content.html('<div class="os-hub-modal-loading" style="padding:32px; text-align:center;"><span class="spinner is-active" style="float:none;"></span></div>');
            $modal.fadeIn(150);

            $.post(AJAX_URL, $.extend({
                action: 'os_hub_load_form',
                nonce: NONCE,
                form: form,
                uid: customer.uid,
                type: customer.type
            }, extraData || {})).done(function (res) {
                if (!res || !res.success) {
                    $content.html('<div class="notice notice-error inline" style="margin:20px;"><p>' + ((res && res.data && res.data.message) || 'تعذر تحميل النموذج.') + '</p></div>');
                    return;
                }

                window.payerChildren = res.data.participants || [];
                $content.html(res.data.html || '');

                if (typeof $.fn.datepicker !== 'undefined') {
                    $content.find('.olama-reg-datepicker, .os-datepicker').each(function () {
                        var $field = $(this);
                        if (!$field.hasClass('hasDatepicker')) {
                            $field.datepicker({ dateFormat: 'yy-mm-dd', changeYear: true, changeMonth: true, yearRange: '1970:2050' });
                        }
                    });
                }

                if (typeof $.fn.select2 !== 'undefined') {
                    $content.find('select').each(function () {
                        var $select = $(this);
                        if (!$select.hasClass('select2-hidden-accessible') && !$select.prop('disabled')) {
                            $select.select2({ dir: 'rtl', width: '100%', dropdownParent: $modal });
                        }
                    });
                }

                if (extraData && extraData.openFees) {
                    $content
                        .find('.olama-contract-tabs .nav-tab[href="#tab-fees"]')
                        .first()
                        .removeClass('os-disabled')
                        .trigger('click');
                }
            }).fail(function () {
                $content.html('<div class="notice notice-error inline" style="margin:20px;"><p>تعذر تحميل النموذج.</p></div>');
            });
        },

        close: function () {
            $('#os-hub-original-form-modal').fadeOut(150, function () {
                $('#os-hub-original-form-content').empty();
            });
        },
    };

    var QuickActions = {

        show: function (customer) {
            var $bar = $('#os-hub-quick-actions');

            // Build URLs
            var invoiceUrl       = ADMIN_URL + 'admin.php?page=olama-registration-invoices&action=new';
            var agreementUrl     = ADMIN_URL + 'admin.php?page=olama-registration-agreements&action=new';
            var paymentUrl       = ADMIN_URL + 'admin.php?page=olama-registration-payments&action=new';
            var settlementUrl    = ADMIN_URL + 'admin.php?page=olama-registration-settlements';
            var customPaymentUrl = ADMIN_URL + 'admin.php?page=olama-registration-custom-payments';

            if (customer.type === 'family') {
                invoiceUrl       += '&family_uid='  + encodeURIComponent(customer.uid);
                agreementUrl     += '&payer_type=family&payer_uid=' + encodeURIComponent(customer.uid);
                paymentUrl       += '&family_uid='  + encodeURIComponent(customer.uid);
                settlementUrl    += '&family_uid='  + encodeURIComponent(customer.uid);
                customPaymentUrl += '&family_uid='  + encodeURIComponent(customer.uid);
                $bar.find('.os-hub-qaction--family-only').show();
            } else {
                agreementUrl     += '&payer_type=customer&payer_uid=' + encodeURIComponent(customer.uid);
                customPaymentUrl += '&ext_customer_id=' + encodeURIComponent(customer.internal_id || customer.id || '');
                $bar.find('.os-hub-qaction--family-only').hide();
            }

            $('#os-hub-qaction-invoice').attr('href', invoiceUrl);
            $('#os-hub-qaction-agreement').attr('href', agreementUrl);
            $('#os-hub-qaction-payment').attr('href', paymentUrl);
            $('#os-hub-qaction-settlement').attr('href', settlementUrl);
            $('#os-hub-qaction-custom-payment').attr('href', customPaymentUrl);

            $bar.show();
        },

        hide: function () {
            $('#os-hub-quick-actions').hide();
        },
    };

    // ── TileShortcuts: keyboard 1–8 to open tile panels ──────────────────
    var TileShortcuts = {

        tileIds: ['profile','agreements','invoices','payments','children','financial','statement','history','settlements'],

        init: function () {
            var self = this;
            // Show legend when hub panel is reached
            $(document).on('keydown', function (e) {
                if ($(e.target).is('input, textarea, select, [contenteditable]')) return;
                if (state.currentPanel !== 'hub') return;

                var idx = parseInt(e.key, 10) - 1;
                if (isNaN(idx) || idx < 0 || idx >= self.tileIds.length) return;

                e.preventDefault();
                var tileId = self.tileIds[idx];
                var $btn   = $('#os-hub-tile-btn-' + tileId);
                var $panel = $('#os-hub-tile-panel-' + tileId);

                if (!$btn.length || $btn.closest('.os-hub-tile-item').hasClass('os-hub-tile-item--hidden')) return;

                var isOpen = $btn.attr('aria-expanded') === 'true';
                TileManager.collapseAll();
                if (!isOpen) {
                    TileManager.open(tileId, $btn, $panel);
                }
                $btn[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        },
    };

    // ── ChildrenInlineAdd: add child form in children tile ─────────────
    var ChildrenInlineAdd = {

        init: function () {
            // Show add-child form
            $(document).on('click', '#os-hub-add-child-btn', function () {
                var $wrap = $(this).closest('.os-hub-add-child-wrap');
                $wrap.find('#os-hub-add-child-form').slideDown(200);
                $wrap.find('#os-hub-add-child-btn').hide();
                $wrap.find('#os-hub-child-name-input').focus();
            });

            // Cancel
            $(document).on('click', '.os-hub-add-child-cancel', function () {
                var $wrap = $(this).closest('.os-hub-add-child-wrap');
                $wrap.find('#os-hub-add-child-form').slideUp(160);
                $wrap.find('#os-hub-add-child-btn').show();
                $wrap.find('#os-hub-add-child-form input').val('');
            });

            // Submit
            $(document).on('submit', '#os-hub-add-child-form', function (e) {
                e.preventDefault();
                ChildrenInlineAdd.submit($(this));
            });
        },

        submit: function ($form) {
            var childName = $form.find('[name="child_name"]').val().trim();
            if (!childName) {
                alert(I18N.childNameRequired || 'اسم الابن مطلوب.');
                $form.find('[name="child_name"]').focus();
                return;
            }

            var $submitBtn = $form.find('.os-hub-add-child-submit');
            $submitBtn.prop('disabled', true)
                      .html('<span class="os-hub-spinner" style="width:14px;height:14px;border-width:2px;" aria-hidden="true"></span> جاري...');

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data: {
                    action:     'os_hub_add_child',
                    nonce:      NONCE,
                    uid:        state.currentCustomer.uid,
                    child_name: childName,
                    grade:      $form.find('[name="grade"]').val().trim(),
                },
                success: function (response) {
                    $submitBtn.prop('disabled', false)
                              .html('<span class="dashicons dashicons-saved" aria-hidden="true"></span> حفظ');

                    if (!response.success) {
                        ChildrenInlineAdd.toast($form, response.data.message || 'حدث خطأ.', 'error');
                        return;
                    }

                    // Show toast
                    ChildrenInlineAdd.toast($form, response.data.message, 'success');

                    // Reload the children tile
                    var $panel   = $('#os-hub-tile-panel-children');
                    var $content = $panel.find('.os-hub-tile-panel__content');
                    $content.removeData('loaded').empty();
                    TileManager.collapseAll();
                    TileManager.open('children', $('#os-hub-tile-btn-children'), $panel);

                    // Refresh counts badge
                    CountsLoader.load(state.currentCustomer.uid, state.currentCustomer.type);
                },
                error: function () {
                    $submitBtn.prop('disabled', false)
                              .html('<span class="dashicons dashicons-saved" aria-hidden="true"></span> حفظ');
                    ChildrenInlineAdd.toast($form, 'خطأ في الاتصال.', 'error');
                },
            });
        },

        toast: function ($form, message, type) {
            var $toast = $form.closest('.os-hub-add-child-wrap').find('.os-hub-add-child-toast');
            var cls    = type === 'success' ? 'os-hub-toast--success' : 'os-hub-toast--error';
            $toast
                .removeClass('os-hub-toast--success os-hub-toast--error')
                .addClass(cls).text(message).stop(true).css({opacity:1})
                .fadeIn(200).delay(3000).fadeOut(400, function () { $(this).empty().removeClass(cls); });
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // ProfileEditor — Phase 3: inline edit + activate/deactivate on profile tile
    // ══════════════════════════════════════════════════════════════════════════
    var ProfileEditor = {

        init: function () {
            // ── Edit button: toggle between view and form ──────────────────────
            $(document).on('click', '.os-hub-edit-btn', function () {
                var $wrap  = $(this).closest('.os-hub-profile-wrap');
                ProfileEditor.showForm($wrap);
            });

            // ── Cancel button ──────────────────────────────────────────────
            $(document).on('click', '.os-hub-form-cancel', function () {
                var $wrap = $(this).closest('.os-hub-profile-wrap');
                ProfileEditor.showView($wrap);
            });

            // ── Form submit (save profile) ──────────────────────────────────
            $(document).on('submit', '.os-hub-profile-form', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $wrap = $form.closest('.os-hub-profile-wrap');
                ProfileEditor.saveProfile($form, $wrap);
            });

            // ── Toggle active/inactive ─────────────────────────────────────
            $(document).on('click', '.os-hub-toggle-active-btn', function () {
                var $btn   = $(this);
                var $wrap  = $btn.closest('.os-hub-profile-wrap');
                var uid    = $wrap.data('uid');
                var type   = $wrap.data('type');
                var active = $btn.data('active'); // current state (1=active, 0=inactive)
                var label  = active == 1
                    ? 'هل تريد تعطيل هذا الملف؟'
                    : 'هل تريد تفعيل هذا الملف؟';

                if (!window.confirm(label)) return;

                ProfileEditor.toggleActive($btn, $wrap, uid, type, active);
            });
        },

        // Switch to inline form
        showForm: function ($wrap) {
            $wrap.find('.os-hub-profile-view').slideUp(160);
            $wrap.find('.os-hub-profile-form').slideDown(200);
            $wrap.find('.os-hub-edit-btn').addClass('button-primary');
            $wrap.find('.os-hub-profile-form input:first').focus();
        },

        // Switch back to read-only view
        showView: function ($wrap) {
            $wrap.find('.os-hub-profile-form').slideUp(160);
            $wrap.find('.os-hub-profile-view').slideDown(200);
            $wrap.find('.os-hub-edit-btn').removeClass('button-primary');
            $wrap.find('.os-hub-profile-toast').empty();
        },

        // AJAX: save profile edits
        saveProfile: function ($form, $wrap) {
            var $submitBtn = $form.find('.os-hub-form-save');
            $submitBtn.prop('disabled', true)
                      .html('<span class="os-hub-spinner" aria-hidden="true"></span> جاري...');

            var formData = $form.serializeArray();
            formData.push({ name: 'action', value: 'os_hub_save_profile' });
            formData.push({ name: 'nonce',  value: NONCE });

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data:   formData,
                success: function (response) {
                    $submitBtn.prop('disabled', false)
                              .html('<span class="dashicons dashicons-saved" aria-hidden="true"></span> حفظ التغييرات');

                    if (!response.success) {
                        ProfileEditor.toast($wrap, response.data.message || 'حدث خطأ.', 'error');
                        return;
                    }

                    // Update the customer name in the identity header
                    if (response.data.name) {
                        $('#os-hub-identity-name').text(response.data.name);
                        if (state.currentCustomer) {
                            state.currentCustomer.name = response.data.name;
                            RecentLookups.save(state.currentCustomer);
                        }
                    }

                    ProfileEditor.toast($wrap, response.data.message, 'success');
                    ProfileEditor.showView($wrap);

                    // Bust the tile cache so it reloads next open
                    var $content = $wrap.closest('.os-hub-tile-panel__content');
                    $content.removeData('loaded');
                },
                error: function () {
                    $submitBtn.prop('disabled', false)
                              .html('<span class="dashicons dashicons-saved" aria-hidden="true"></span> حفظ التغييرات');
                    ProfileEditor.toast($wrap, 'خطأ في الاتصال. حاول مجدداً.', 'error');
                },
            });
        },

        // AJAX: toggle active status
        toggleActive: function ($btn, $wrap, uid, type, active) {
            $btn.prop('disabled', true);

            $.ajax({
                url:    AJAX_URL,
                method: 'POST',
                data: {
                    action: 'os_hub_toggle_active',
                    nonce:  NONCE,
                    uid:    uid,
                    type:   type,
                    active: active,
                },
                success: function (response) {
                    $btn.prop('disabled', false);

                    if (!response.success) {
                        ProfileEditor.toast($wrap, response.data.message || 'حدث خطأ.', 'error');
                        return;
                    }

                    var isNowActive = response.data.is_active === 1;

                    // Update button state
                    $btn.data('active', isNowActive ? '1' : '0');
                    $btn.find('.dashicons')
                        .removeClass('dashicons-lock dashicons-unlock')
                        .addClass(isNowActive ? 'dashicons-lock' : 'dashicons-unlock');
                    $btn.contents().filter(function () {
                        return this.nodeType === 3; // text nodes
                    }).last().replaceWith(isNowActive ? 'تعطيل' : 'تفعيل');

                    // Update status badge in the read-only view table
                    var $statusRow = $wrap.find('.os-hub-profile-view tbody tr').last();
                    $statusRow.find('td').html(
                        isNowActive
                            ? '<span class="os-hub-badge os-hub-badge--green">نشط</span>'
                            : '<span class="os-hub-badge os-hub-badge--gray">غير نشط</span>'
                    );

                    ProfileEditor.toast($wrap, response.data.message, 'success');

                    // Bust tile cache
                    $wrap.closest('.os-hub-tile-panel__content').removeData('loaded');
                },
                error: function () {
                    $btn.prop('disabled', false);
                    ProfileEditor.toast($wrap, 'خطأ في الاتصال.', 'error');
                },
            });
        },

        // Show inline toast notification
        toast: function ($wrap, message, type) {
            var $toast = $wrap.find('.os-hub-profile-toast');
            var cls    = type === 'success' ? 'os-hub-toast--success' : 'os-hub-toast--error';
            $toast
                .removeClass('os-hub-toast--success os-hub-toast--error')
                .addClass(cls)
                .text(message)
                .stop(true)
                .css({ opacity: 1 })
                .fadeIn(200)
                .delay(3000)
                .fadeOut(400, function () { $(this).empty().removeClass(cls); });
        },
    };

    // ══════════════════════════════════════════════════════════════════════════
    // Utility
    // ══════════════════════════════════════════════════════════════════════════
    function escHtml(str) {
        return $('<div>').text(str).html();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Init — boot all modules when DOM is ready
    // ══════════════════════════════════════════════════════════════════════════
    $(function () {
        TypeSelector.init();
        RecentLookups.init();
        FamilyCreateModal.init();
        SearchModule.init();
        TileManager.init();
        Navigation.init();
        ProfileEditor.init();
        // Phase 4
        YearSelector.init();
        TileShortcuts.init();
        ChildrenInlineAdd.init();
        // Phase 5
        TileReloader.init();
        UidCopy.init();

        $(document).on('click', '#os-hub-original-form-modal .olama-reg-modal-close', function () {
            OriginalFormModal.close();
        });

        $(document).on('osHub:agreementSaved', function (e, agreement) {
            refreshDashboardTiles(['agreements', 'invoices', 'financial', 'statement', 'history']);

            // A new agreement is initially rendered without the fees table.
            // Reload it in edit mode once it has an ID so fee rows can be added.
            if (
                agreement &&
                agreement.wasNew &&
                agreement.id &&
                $('#os-hub-original-form-modal').is(':visible')
            ) {
                var title = 'تعديل العقد';
                if (agreement.agreement_number) {
                    title += ' #' + agreement.agreement_number;
                }

                OriginalFormModal.open('agreement', title, {
                    id: agreement.id,
                    openFees: 1
                });
            }
        });

        $(document).on('osHub:invoiceSaved', function () {
            refreshDashboardTiles(['invoices', 'financial', 'statement', 'history']);
        });

        $(document).on('osHub:paymentSaved', function () {
            refreshDashboardTiles(['payments', 'invoices', 'financial', 'statement', 'history']);
        });

        // Intercept Invoice Quick Action click to open modal inline on customer hub page
        $(document).on('click', '#os-hub-qaction-invoice', function (e) {
            e.preventDefault();
            var customer = state.currentCustomer;
            if (!customer || customer.type !== 'family') return;

            var $familySelect = $('#inv_family_uid');
            if ($familySelect.length) {
                // Pre-select the customer
                $familySelect.empty().append(new Option(customer.name + ' (' + customer.uid + ')', customer.uid, true, true));
                $familySelect.prop('disabled', true);

                // Add hidden input if not exists to ensure it submits when disabled
                var $form = $('#olama-reg-invoice-form');
                var $hidden = $form.find('input[name="family_uid"]');
                if (!$hidden.length) {
                    $hidden = $('<input type="hidden" name="family_uid">');
                    $form.append($hidden);
                }
                $hidden.val(customer.uid);

                // Trigger select2 init
                if (typeof $.fn.select2 !== 'undefined' && !$familySelect.hasClass('select2-hidden-accessible')) {
                    $familySelect.select2({
                        dir: 'rtl',
                        width: '100%',
                        dropdownParent: $('#olama-reg-invoice-modal'),
                        placeholder: 'ابحث عن عائلة باستخدام رقم الملف أو الاسم...',
                        minimumInputLength: 2
                    });
                }

                // Open the modal
                $('#olama-reg-invoice-modal').fadeIn(200);

                // Trigger change so student list loads
                $familySelect.trigger('change');
            }
        });

        // Intercept Agreement Quick Action click to open modal inline on customer hub page
        $(document).on('click', '#os-hub-qaction-agreement', function (e) {
            e.preventDefault();
            var customer = state.currentCustomer;
            if (!customer) return;

            OriginalFormModal.open('agreement', 'إضافة عقد جديد');
        });

        // Intercept Edit Agreement action click in dashboard table
        $(document).on('click', '.os-hub-edit-agreement', function (e) {
            e.preventDefault();
            var agreementId = $(this).data('id');
            var customer = state.currentCustomer;
            if (!customer || !agreementId) return;

            OriginalFormModal.open('agreement', 'تعديل العقد', { id: agreementId });
        });

        // Intercept Payment Quick Action click to open modal inline on customer hub page
        $(document).on('click', '#os-hub-qaction-payment', function (e) {
            e.preventDefault();
            var customer = state.currentCustomer;
            if (!customer) return;

            var $searchFamily = $('#pay_search_family');
            if ($searchFamily.length) {
                // Pre-populate Select2 with the active customer/family details
                $searchFamily.empty().append(new Option(customer.name + ' (' + customer.uid + ')', customer.uid, true, true));
                $searchFamily.prop('disabled', true);

                // Set family UID in hidden input
                $('#pay_family_uid').val(customer.uid);

                // Show family search wrapper (which contains both family select and invoice select)
                $('#pay_family_search_wrap').show();
                $('#pay_invoice_select_wrap').show();
                $('#pay_invoice_display_wrap').hide();

                // Set date to today
                var today = new Date().toISOString().split('T')[0];
                $('#pay_date').val(today);

                // Load family invoices directly via AJAX (trigger('change') won't fire on disabled element)
                var $invSelect = $('#pay_select_invoice');
                $invSelect.empty().append('<option value="">جاري تحميل الفواتير...</option>');
                $('#pay_invoice_id').val('');
                $('#pay_invoice_bal_lbl').text('0.00 د.أ');
                $('#pay_amount').val('0.00').attr('min', '0.01').removeAttr('max');

                $.post(AJAX_URL, {
                    action:           'olama_reg_get_family_billing',
                    nonce:            REG_NONCE,
                    family_uid:       customer.uid,
                    academic_year_id: state.currentYearId || 0
                }, function (res) {
                    $invSelect.empty().append('<option value="">-- اختر الفاتورة --</option>');
                    if (res.success && res.data.invoices) {
                        res.data.invoices.forEach(function (inv) {
                            if (parseFloat(inv.balance) > 0 && inv.status !== 'cancelled' && inv.status !== 'draft') {
                                $invSelect.append('<option value="' + inv.id + '" data-bal="' + inv.balance + '">' + inv.invoice_number + ' (المتبقي: ' + parseFloat(inv.balance).toFixed(2) + ')</option>');
                            }
                        });
                    }
                });

                // Open the modal
                $('#olama-reg-payment-modal').fadeIn(200);

                // Initialize datepicker
                if (typeof $.fn.datepicker !== 'undefined') {
                    $('#olama-reg-payment-modal').find('.olama-reg-datepicker, .os-datepicker').each(function () {
                        if (!$(this).hasClass('hasDatepicker')) {
                            $(this).datepicker({ dateFormat: 'yy-mm-dd', changeYear: true, changeMonth: true, yearRange: '1970:2050' });
                        }
                    });
                }
            }
        });

        // Intercept click on the "دفع" button inside the invoice table
        $(document).on('click', '.os-hub-pay-invoice-btn', function (e) {
            e.preventDefault();
            var invoiceId = $(this).data('id');
            var customer = state.currentCustomer;
            if (!customer) return;

            var $searchFamily = $('#pay_search_family');
            if ($searchFamily.length) {
                // Pre-populate Select2 with the active customer/family details
                $searchFamily.empty().append(new Option(customer.name + ' (' + customer.uid + ')', customer.uid, true, true));
                $searchFamily.prop('disabled', true);

                // Set family UID in hidden input
                $('#pay_family_uid').val(customer.uid);

                // Show family search wrapper (contains family select and invoice select)
                $('#pay_family_search_wrap').show();
                $('#pay_invoice_select_wrap').show();
                $('#pay_invoice_display_wrap').hide();

                // Set date to today
                var today = new Date().toISOString().split('T')[0];
                $('#pay_date').val(today);

                // Initialize invoice select with loading message
                var $invSelect = $('#pay_select_invoice');
                $invSelect.empty().append('<option value="">جاري تحميل الفواتير...</option>');
                $('#pay_invoice_id').val('');
                $('#pay_invoice_bal_lbl').text('0.00 د.أ');
                $('#pay_amount').val('0.00').attr('min', '0.01').removeAttr('max');

                $.post(AJAX_URL, {
                    action:           'olama_reg_get_family_billing',
                    nonce:            REG_NONCE,
                    family_uid:       customer.uid,
                    academic_year_id: state.currentYearId || 0
                }, function (res) {
                    $invSelect.empty().append('<option value="">-- اختر الفاتورة --</option>');
                    if (res.success && res.data.invoices) {
                        res.data.invoices.forEach(function (inv) {
                            if (parseFloat(inv.balance) > 0 && inv.status !== 'cancelled' && inv.status !== 'draft') {
                                var isSelected = (parseInt(inv.id) === parseInt(invoiceId)) ? 'selected' : '';
                                $invSelect.append('<option value="' + inv.id + '" data-bal="' + inv.balance + '" ' + isSelected + '>' + inv.invoice_number + ' (المتبقي: ' + parseFloat(inv.balance).toFixed(2) + ')</option>');
                            }
                        });
                    }
                    // Trigger change so the amount fields and max limits are filled automatically
                    $invSelect.trigger('change');
                });

                // Open the modal
                $('#olama-reg-payment-modal').fadeIn(200);

                // Initialize datepicker
                if (typeof $.fn.datepicker !== 'undefined') {
                    $('#olama-reg-payment-modal').find('.olama-reg-datepicker, .os-datepicker').each(function () {
                        if (!$(this).hasClass('hasDatepicker')) {
                            $(this).datepicker({ dateFormat: 'yy-mm-dd', changeYear: true, changeMonth: true, yearRange: '1970:2050' });
                        }
                    });
                }
            }
        });

        // Intercept Settlement Quick Action click to open modal inline on customer hub page
        $(document).on('click', '#os-hub-qaction-settlement', function (e) {
            e.preventDefault();
            var customer = state.currentCustomer;
            if (!customer || customer.type !== 'family') return;

            var $familySelect = $('#settlement_family_search');
            if ($familySelect.length) {
                // Pre-select the customer
                $familySelect.empty().append(new Option(customer.name + ' (' + customer.uid + ')', customer.uid, true, true));
                $familySelect.prop('disabled', true);

                // Set family UID in hidden input
                $('#settlement_family_id').val(customer.uid);

                // Initialize Select2 if not done
                if (typeof $.fn.select2 !== 'undefined' && !$familySelect.hasClass('select2-hidden-accessible')) {
                    $familySelect.select2({
                        dir: 'rtl',
                        width: '100%',
                        dropdownParent: $('#olama-reg-settlement-modal'),
                        placeholder: 'ابحث بالاسم أو رقم العائلة...',
                        minimumInputLength: 2
                    });
                }

                // Open the modal
                $('#olama-reg-settlement-modal').fadeIn(200);
            }
        });

        // Handle Settlement Form Submit
        $(document).on('submit', '#olama-reg-settlement-form', function (e) {
            e.preventDefault();
            var data = $(this).serialize() + '&action=olama_reg_create_settlement&nonce=' + REG_NONCE;
            var btn = $('#olama-reg-save-settlement-btn');
            btn.prop('disabled', true).text('جاري الحفظ...');

            $.post(AJAX_URL, data, function (res) {
                if (res.success) {
                    hubNotice(res.data.message);
                    $('#olama-reg-settlement-modal').fadeOut(200);
                    refreshDashboardTiles(['settlements', 'history', 'financial']);
                } else {
                    alert(res.data.message || 'حدث خطأ');
                }
            }).always(function () {
                btn.prop('disabled', false).text('حفظ وإصدار الإيصال');
            });
        });

        // Handle Settle Button Click in Hub table
        $(document).on('click', '.btn-settle-receipt', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            var amount = $(this).data('amount');
            $('#settle-receipt-id').val(id);
            $('#settle-amount-display').val(amount);
            $('#modal-settle-receipt').fadeIn(200);
        });

        // Handle Close Settle Modal Click
        $(document).on('click', '#modal-settle-receipt .btn-close-modal, #modal-settle-receipt .olama-reg-modal-close', function (e) {
            e.preventDefault();
            $('#modal-settle-receipt').fadeOut(200);
        });

        // Handle Settle Form Submit
        $(document).on('submit', '#form-settle-receipt', function (e) {
            e.preventDefault();
            var data = $(this).serialize() + '&action=olama_reg_settle_receipt&nonce=' + REG_NONCE;
            var btn = $(this).find('button[type="submit"]');
            btn.prop('disabled', true).text('جاري الحفظ...');

            $.post(AJAX_URL, data, function (res) {
                if (res.success) {
                    hubNotice(res.data.message);
                    $('#modal-settle-receipt').fadeOut(200);
                    refreshDashboardTiles(['settlements', 'history', 'financial']);
                } else {
                    alert(res.data.message || 'حدث خطأ');
                }
            }).always(function () {
                btn.prop('disabled', false).text('تأكيد التسوية');
            });
        });

        // Handle Cancel Settlement Button Click
        $(document).on('click', '.btn-cancel-receipt', function (e) {
            e.preventDefault();
            if (!confirm('هل أنت متأكد من إلغاء هذا الإيصال؟')) return;

            var id = $(this).data('id');
            $.post(AJAX_URL, {
                action: 'olama_reg_cancel_settlement',
                nonce: REG_NONCE,
                id: id
            }, function (res) {
                if (res.success) {
                    hubNotice(res.data.message);
                    refreshDashboardTiles(['settlements', 'history', 'financial']);
                } else {
                    alert(res.data.message || 'حدث خطأ');
                }
            });
        });

        // Intercept Custom Payment Quick Action click to open modal inline on customer hub page
        $(document).on('click', '#os-hub-qaction-custom-payment', function (e) {
            e.preventDefault();
            var customer = state.currentCustomer;
            if (!customer) return;

            var isFamily = (customer.type === 'family');

            // Clear lists, loading, and messages
            $('#cp_students_list').empty();
            $('#cp_ext_students_list').empty();
            $('#cp_total_display').text('0.00 د.أ');
            $('#cp_amount').val('');
            $('#cp_discount').val('0.00');
            $('#cp_service_type').val('');
            $('#cp_fee_template').val('');
            $('#cp_response_msg').hide();
            $('#cp_loading').hide();

            // Ensure there is always a hidden input carrying customer_type
            // (disabled radio buttons are ignored by serialize())
            var $cpForm = $('#olama-reg-custom-payment-form');
            $cpForm.find('input[name="customer_type"][type="hidden"]').remove();
            $cpForm.append('<input type="hidden" name="customer_type" value="' + (isFamily ? 'internal' : 'external') + '">');

            // Set inputs
            if (isFamily) {
                $('#cp_type_internal').prop('checked', true);
                $('#cp_internal_customer_wrap').show();
                $('#cp_external_customer_wrap').hide();

                // Prefill and lock select
                var $familySelect = $('#cp_family_search');
                $familySelect.empty().append(new Option(customer.name + ' (' + customer.uid + ')', customer.uid, true, true));
                $familySelect.prop('disabled', true);
                $('#cp_family_uid').val(customer.uid);
                $('#cp_customer_uid').val('');

                // Load students directly via AJAX (trigger('change') won't fire on disabled element)
                var $list = $('#cp_students_list');
                $('#cp_students_container').show();
                $list.html('<div style="grid-column:1/-1; text-align:center; color:var(--reg-primary);"><span class="spinner is-active" style="float:none;"></span> جاري تحميل الطلاب...</div>');

                $.post(AJAX_URL, {
                    action:     'olama_reg_get_family_students',
                    nonce:      REG_NONCE,
                    family_uid: customer.uid
                }, function (res) {
                    $list.empty();
                    if (res.success && res.data.students && res.data.students.length) {
                        res.data.students.forEach(function (st) {
                            $list.append(
                                '<label style="display:flex; align-items:center; gap:8px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">'
                                + '<input type="checkbox" name="student_uids[]" value="' + escHtml(st.student_uid) + '" class="cp-student-check" checked style="width:16px;height:16px;">'
                                + '<span>'
                                + '<span style="display:block; font-weight:700;">' + escHtml(st.student_name || 'بدون اسم') + '</span>'
                                + '<span style="display:block; font-size:11px; color:#64748b;">' + escHtml(st.grade_name || '') + '</span>'
                                + '</span></label>'
                            );
                        });
                    } else {
                        $list.html('<div style="grid-column:1/-1; color:#dc2626;">لا يوجد طلاب نشطين لهذه العائلة.</div>');
                    }
                }).fail(function () {
                    $list.html('<div style="grid-column:1/-1; color:#dc2626;">تعذر تحميل الطلاب.</div>');
                });

            } else {
                $('#cp_type_external').prop('checked', true);
                $('#cp_internal_customer_wrap').hide();
                $('#cp_external_customer_wrap').show();

                // Prefill and lock select
                var $extSelect = $('#cp_ext_customer_search');
                var payerId = customer.internal_id || customer.id || '';
                $extSelect.empty().append(new Option(customer.name + ' (' + customer.uid + ')', payerId, true, true));
                $extSelect.prop('disabled', true);
                $('#cp_ext_customer_id').val(payerId);
                $('#cp_customer_uid').val(customer.uid);

                // Load children directly via AJAX (trigger('change') won't fire on disabled element)
                if (payerId) {
                    $('#cp_ext_no_customer_msg').hide();
                    $('#cp_ext_children_section').show();
                    var $extList = $('#cp_ext_students_list');
                    $extList.html('<div style="grid-column:1/-1; text-align:center; color:var(--reg-primary);"><span class="spinner is-active" style="float:none;"></span> جاري التحميل...</div>');
                    $('#cp_ext_no_children_msg').hide();

                    $.post(AJAX_URL, {
                        action:      'olama_reg_get_external_customer_children',
                        nonce:       REG_NONCE,
                        customer_id: payerId
                    }, function (res) {
                        $extList.empty();
                        var children = (res.success) ? (res.data.children || []) : [];
                        if (children.length) {
                            children.forEach(function (ch) {
                                var chId = 'cp_ext_ch_' + ch.id;
                                $extList.append(
                                    '<label for="' + chId + '" style="display:flex; align-items:center; gap:8px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">'
                                    + '<input type="checkbox" id="' + chId + '" class="cp-ext-student-check" name="child_ids[]" value="' + ch.id + '" checked style="width:16px;height:16px;">'
                                    + '<span>'
                                    + '<span style="display:block; font-weight:700;">' + escHtml(ch.child_name) + '</span>'
                                    + '<span style="display:block; font-size:11px; color:#64748b;">' + escHtml(ch.grade || 'بدون صف') + '</span>'
                                    + '</span></label>'
                                );
                            });
                            $('#cp_ext_no_children_msg').hide();
                        } else {
                            $('#cp_ext_no_children_msg').show();
                        }
                    }).fail(function () {
                        $extList.html('<div style="grid-column:1/-1; color:#dc2626;">تعذر تحميل الأبناء.</div>');
                        $('#cp_ext_no_children_msg').hide();
                    });
                }
            }

            // Open the modal!
            $('#olama-reg-custom-payment-modal').fadeIn(200);
        });

        // Intercept Custom Payment form submit to reload dashboard page contextually on success
        $(document).on('submit', '#olama-reg-custom-payment-form', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation(); // Prevents olama-reg.js submit handler from running!
            
            const isExternal = $('input[name="customer_type"]:checked').val() === 'external';
            const isDirect = $('#cp_ext_pay_customer_direct').is(':checked');
            const internalCount = $('.cp-student-check:checked').length;
            const externalCount = isDirect ? 1 : $('.cp-ext-student-check:checked').length;

            if (!isExternal && internalCount === 0) { $('#cp_students_error').show(); return; }
            if (isExternal && externalCount === 0) { alert('يجب اختيار ابن واحد على الأقل، أو تفعيل "دفعة مباشرة للعميل".'); return; }

            const $form = $(this);
            const $btn = $('#cp_submit_btn');
            const $loading = $('#cp_loading');
            const $msg = $('#cp_response_msg');

            $btn.prop('disabled', true); $loading.show(); $msg.hide();

            let formData = $form.serialize();

            if (isExternal) {
                const extCustomerId = parseInt($('#cp_ext_customer_id').val()) || 0;
                if (!extCustomerId) {
                    $msg.html('<div class="notice notice-error inline" style="padding:10px;"><p>يرجى تحديد عميل خارجي.</p></div>').fadeIn();
                    $btn.prop('disabled', false); $loading.hide(); return;
                }
                formData += '&is_external_customer=1';
                if (isDirect) {
                    formData = formData.replace(/child_ids%5B%5D=[^&]*/g, '').replace(/child_ids%5B%5D/g, '');
                }
            }

            $.post(AJAX_URL, 'action=olama_reg_save_custom_payment&nonce=' + REG_NONCE + '&' + formData)
                .done(res => {
                    if (res.success) {
                        $msg.html('<div class="notice notice-success inline" style="padding:10px;"><p>' + res.data.message + '</p></div>').fadeIn();
                        
                        // Open receipt in new tab
                        const invoiceId = res.data.payment_id || (res.data.invoice_ids && res.data.invoice_ids[0]);
                        if (invoiceId) {
                            const url = new URL(window.location.href);
                            url.searchParams.set('page', 'olama-registration-payments');
                            url.searchParams.set('action', 'print_receipt');
                            url.searchParams.set('id', invoiceId);
                            window.open(url.toString(), '_blank');
                        }

                        $('#olama-reg-custom-payment-modal').fadeOut(200);
                        hubNotice(res.data.message);
                        refreshDashboardTiles(['invoices', 'payments', 'financial', 'statement', 'history']);
                    } else {
                        $msg.html('<div class="notice notice-error inline" style="padding:10px;"><p>' + (res.data?.message || 'حدث خطأ.') + '</p></div>').fadeIn();
                    }
                })
                .always(() => { $btn.prop('disabled', false); $loading.hide(); });
        });

        // Audit log (History) filters, sorting, and pagination handlers
        $(document).on('click', '#history-filter-apply-btn', function () {
            var $panel = $('#os-hub-tile-panel-history');
            var $btn = $('#os-hub-tile-btn-history');
            
            var extraData = {
                search:           $('#history-filter-search').val(),
                date_from:        $('#history-filter-date-from').val(),
                date_to:          $('#history-filter-date-to').val(),
                amount:           $('#history-filter-amount').val(),
                service_type:     $('#history-filter-service-type').val(),
                agreement_nature: $('#history-filter-agreement-nature').val(),
                orderby:          $('#history-filter-orderby').val(),
                order:            $('#history-sort-toggle-btn').data('order') || 'desc',
                paged:            1
            };
            
            TileManager.open('history', $btn, $panel, extraData);
        });

        $(document).on('click', '#history-sort-toggle-btn', function () {
            var currentOrder = $(this).data('order') || 'desc';
            var newOrder = (currentOrder === 'desc') ? 'asc' : 'desc';
            $(this).data('order', newOrder);
            
            // Re-trigger apply
            $('#history-filter-apply-btn').trigger('click');
        });

        $(document).on('click', '#history-header-date', function () {
            var nextOrder = $(this).data('order') || 'desc';
            
            // Force orderby to date
            $('#history-filter-orderby').val('date');
            
            // Update sort toggle btn order data attribute
            $('#history-sort-toggle-btn').data('order', nextOrder);
            
            // Re-trigger apply
            $('#history-filter-apply-btn').trigger('click');
        });

        $(document).on('click', '.history-paged-btn', function () {
            var page = $(this).data('page');
            var $panel = $('#os-hub-tile-panel-history');
            var $btn = $('#os-hub-tile-btn-history');
            
            var extraData = {
                search:           $('#history-filter-search').val(),
                date_from:        $('#history-filter-date-from').val(),
                date_to:          $('#history-filter-date-to').val(),
                amount:           $('#history-filter-amount').val(),
                service_type:     $('#history-filter-service-type').val(),
                agreement_nature: $('#history-filter-agreement-nature').val(),
                orderby:          $('#history-filter-orderby').val(),
                order:            $('#history-sort-toggle-btn').data('order') || 'desc',
                paged:            page
            };
            
            TileManager.open('history', $btn, $panel, extraData);
        });

        // Handle student selection change in statement tile
        $(document).on('change', '#os-hub-statement-student-select', function () {
            var studentUid = $(this).val();
            var $panel = $('#os-hub-tile-panel-statement');
            var $btn = $('#os-hub-tile-btn-statement');
            
            var extraData = {
                student_uid: studentUid
            };
            
            TileManager.open('statement', $btn, $panel, extraData);
        });

        // Phase 6: Preload customer/family if passed in URL
        if (HUB_DATA.preloadCustomer) {
            state.currentType = HUB_DATA.preloadCustomer.type;
            TypeBadge.update(state.currentType);
            SearchModule.reset();
            RecentLookups.render(state.currentType);
            CustomerHub.loadCustomer(HUB_DATA.preloadCustomer);
        }
    });

}(jQuery));
