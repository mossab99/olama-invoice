/* global olamaReg */
(function ($) {
    'use strict';

    const R = olamaReg; // config injected by wp_localize_script

    function cashAmount(value) {
        const amount = parseFloat(value);
        return Number.isFinite(amount) ? amount : 0;
    }

    function syncCashOpeningReconciliation($form) {
        const reference = cashAmount($form.find('[name="account_id"] option:selected').data('reference-balance'));
        const rawActual = $form.find('[name="opening_balance"]').val();
        const hasActual = rawActual !== '';
        const difference = cashAmount(rawActual) - reference;
        const hasDifference = hasActual && Math.abs(difference) >= 0.01;
        const $variance = $form.find('.os-cash-opening-variance');
        const $acknowledgement = $variance.find('[name="opening_variance_acknowledged"]');

        $form.find('.os-cash-reference-balance').text(reference.toFixed(2));
        $form.find('.os-cash-opening-variance-value').text(difference.toFixed(2));
        $variance.prop('hidden', !hasDifference);
        $acknowledgement.prop('required', hasDifference);
        if (!hasDifference) {
            $acknowledgement.prop('checked', false);
        }
        $form.find('[name="notes"]').prop('required', hasDifference);
    }

    $(document).on('input change', '.os-cash-session-open-form [name="account_id"], .os-cash-session-open-form [name="opening_balance"]', function () {
        syncCashOpeningReconciliation($(this).closest('form'));
    });

    $(document).on('input', '.os-cash-session-close-form [name="actual_closing_balance"]', function () {
        const $form = $(this).closest('form');
        const rawActual = $(this).val();
        const difference = cashAmount(rawActual) - cashAmount($form.data('expected'));
        const hasDifference = rawActual !== '' && Math.abs(difference) >= 0.01;

        $form.find('.os-cash-closing-variance')
            .prop('hidden', rawActual === '')
            .find('strong')
            .text(difference.toFixed(2));
        $form.find('[name="notes"]').prop('required', hasDifference);
    });

    $(function () {
        $('.os-cash-session-open-form').each(function () {
            syncCashOpeningReconciliation($(this));
        });
    });

    // ── Utilities ────────────────────────────────────────────────────────────

    function showNotice(msg, isError = false) {
        let $n = $('#olama-reg-notice');
        if (!$n.length) {
            const $anchor = $('.olama-reg-page-header').first();
            $n = $('<div id="olama-reg-notice" class="olama-reg-notice" style="display:none; white-space:pre-line;"></div>');
            if ($anchor.length) {
                $anchor.after($n);
            } else {
                $('body').prepend($n);
            }
        }
        $n.removeClass('olama-reg-notice--error')
            .text(msg)
            .show()
            .css('display', 'block');
        if (isError) $n.addClass('olama-reg-notice--error');
        clearTimeout(window._regNoticeTimer);
        window._regNoticeTimer = setTimeout(() => $n.fadeOut(), 6000);
    }

    function setLoading($btn, loading) {
        if (loading) {
            $btn.prop('disabled', true).addClass('olama-reg-saving');
            $btn.data('orig-text', $btn.text());
            $btn.text(R.strings.saving);
        } else {
            $btn.prop('disabled', false).removeClass('olama-reg-saving');
            if ($btn.data('orig-text')) $btn.text($btn.data('orig-text'));
        }
    }

    function ajax(action, data, $btn) {
        if ($btn) setLoading($btn, true);
        return $.post(R.ajaxurl, { action, nonce: R.nonce, ...data })
            .always(() => { if ($btn) setLoading($btn, false); });
    }

    function parseMoney(value) {
        const cleaned = String(value || '')
            .replace(/[,،]/g, '')
            .replace(/[^\d.\-]/g, '');
        const parsed = parseFloat(cleaned);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    // ── Tab Navigation ────────────────────────────────────────────────────────

    $(document).on('click', '.olama-reg-tab', function () {
        if ($(this).prop('disabled')) return;
        const tab = $(this).data('tab');
        $('.olama-reg-tab').removeClass('active');
        $(this).addClass('active');
        $('.olama-reg-tab-pane').removeClass('active').hide();
        $('#tab-' + tab).addClass('active').show();

        if (tab === 'financial' && typeof loadFamilyBilling === 'function') {
            loadFamilyBilling();
        }

        // Update URL hash
        if (history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            history.replaceState(null, '', url.toString());
        }
    });

    // Show correct pane on load
    (function () {
        const $active = $('.olama-reg-tab.active');
        if ($active.length) {
            const tab = $active.data('tab');
            $('.olama-reg-tab-pane').hide();
            $('#tab-' + tab).show().addClass('active');
        }
    })();

    // ── Sub-tabs ──────────────────────────────────────────────────────────────

    $(document).on('click', '.olama-reg-sub-tab', function () {
        const subtab = $(this).data('subtab');
        const $container = $(this).closest('.olama-reg-student-body');
        $container.find('.olama-reg-sub-tab').removeClass('active');
        $(this).addClass('active');
        $container.find('.olama-reg-sub-pane').hide();
        $('#' + subtab).show();
    });

    // ── Save Family ───────────────────────────────────────────────────────────

    $(document).on('click', '#olama-reg-save-family', function () {
        const $btn = $(this);
        const data = { family_uid: $('#olama-reg-family-uid').val() };

        // Collect all family form fields
        $('[name]', '#tab-family').each(function () {
            const el = this;
            const name = $(el).attr('name');
            if (el.type === 'checkbox') {
                if (el.checked) data[name] = $(el).val();
            } else {
                data[name] = $(el).val();
            }
        });

        ajax('olama_reg_save_family', data, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(R.strings.saved);
                    if (!data.family_uid && res.data.family_uid) {
                        // Redirect to the newly created family
                        const newUid = res.data.family_uid;
                        showNotice(R.strings.familyCreated + newUid);
                        setTimeout(() => {
                            window.location.href = `${window.location.pathname}?page=olama-registration&action=edit&family_uid=${newUid}`;
                        }, 1200);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Student Accordion Toggle ──────────────────────────────────────────────

    $(document).on('click', '.olama-reg-student-row-header', function () {
        const $row = $(this).closest('.olama-reg-student-row');
        const $body = $row.find('.olama-reg-student-body');
        if ($row.hasClass('open')) {
            $row.removeClass('open');
            $body.slideUp(200);
            $(this).find('.olama-reg-student-toggle').attr('aria-expanded', 'false');
        } else {
            $row.addClass('open');
            $body.slideDown(200);
            $(this).find('.olama-reg-student-toggle').attr('aria-expanded', 'true');
            initDatepickers($body);
            initSelect2($body);
        }
    });

    // Prevent toggle firing when clicking buttons inside header
    $(document).on('click', '.olama-reg-student-row-header button', function (e) {
        e.stopPropagation();
    });

    // ── Add Student ───────────────────────────────────────────────────────────

    $(document).on('click', '#olama-reg-add-student', function () {
        const familyUid = $(this).data('family-uid');
        const $btn = $(this);

        ajax('olama_reg_save_student', { family_uid: familyUid }, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(R.strings.studentAdded);
                    // Redirect to show the new student expanded
                    const uid = res.data.student_uid;
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', 'students');
                    url.searchParams.set('student_uid', uid);
                    window.location.href = url.toString();
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Save Student ──────────────────────────────────────────────────────────

    $(document).on('click', '.olama-reg-save-student', function () {
        const $btn = $(this);
        const studentUid = $btn.data('student-uid');
        const familyUid = $btn.data('family-uid');
        const $row = $('#student-' + studentUid);

        const data = { student_uid: studentUid, family_uid: familyUid };

        // Collect student basic fields
        $row.find('.s-field').each(function () {
            const el = this;
            const name = $(el).data('field') || $(el).attr('name');
            if (!name) return;
            if (el.type === 'radio') {
                if (el.checked) data[name] = el.value;
            } else if (el.type === 'checkbox') {
                if (el.checked) data[name] = el.value;
            } else {
                data[name] = $(el).val();
            }
        });

        ajax('olama_reg_save_student', data, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(R.strings.saved);
                    // Update displayed name
                    const s = res.data.student;
                    if (s && s.student_name) {
                        $row.find('.olama-reg-student-name').text(s.student_name);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Save Transport ────────────────────────────────────────────────────────

    $(document).on('click', '.olama-reg-save-transport', function () {
        const $btn = $(this);
        const studentUid = $btn.data('student-uid');
        const $pane = $btn.closest('[id^="transport-"]');
        const data = { student_uid: studentUid };

        $pane.find('.st-field').each(function () {
            const el = this;
            const name = $(el).attr('name');
            if (!name) return;
            if (el.type === 'checkbox') {
                if (el.checked) data[name] = 1;
            } else {
                data[name] = $(el).val();
            }
        });

        ajax('olama_reg_save_transport', data, $btn)
            .done(res => {
                if (res.success) showNotice(R.strings.saved);
                else showNotice(res.data?.message || R.strings.error, true);
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Blacklist Checkbox Toggle ─────────────────────────────────────────────

    $(document).on('change', '[name="blacklist"]', function () {
        const $reason = $(this).closest('.olama-reg-field').find('.olama-reg-blacklist-reason');
        if ($(this).is(':checked')) $reason.show();
        else $reason.hide();
    });

    // ── Photo Upload ──────────────────────────────────────────────────────────

    let mediaUploader;
    $(document).on('click', '.olama-reg-upload-photo', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $wrap = $btn.closest('.olama-reg-photo-upload');
        const $preview = $wrap.find('.olama-reg-photo-preview');
        const $input = $wrap.find('[name="photo_attachment_id"]');

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media({
            title: R.strings.selectPhoto,
            button: { text: R.strings.selectPhoto },
            multiple: false,
            library: { type: 'image' },
        });

        mediaUploader.on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $preview.attr('src', attachment.url);
            $input.val(attachment.id);
        });

        mediaUploader.open();
    });

    // ── Deactivate Family Button ──────────────────────────────────────────────

    $(document).on('click', '.olama-reg-deactivate', function () {
        if (!confirm(R.strings.confirmDeact)) return;
        const uid = $(this).data('uid');
        const $btn = $(this);
        ajax('olama_reg_soft_delete_family', { family_uid: uid }, $btn)
            .done(res => {
                if (res.success) {
                    $btn.closest('tr').find('.olama-reg-badge--active')
                        .removeClass('olama-reg-badge--active')
                        .addClass('olama-reg-badge--inactive')
                        .text($btn.data('label-inactive') || 'غير نشط');
                    showNotice(res.data.message);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    // ── Financial Tab ─────────────────────────────────────────────────────────

    // Reload financial data when year changes
    $(document).on('change', '#olama-reg-fin-year', function () {
        const familyUid = $(this).data('family-uid');
        const yearId = $(this).val();
        ajax('olama_reg_get_financial', { family_uid: familyUid, academic_year_id: yearId })
            .done(res => {
                if (res.success) {
                    renderFinTable(res.data.rows, res.data.totals, familyUid, yearId);
                }
            });
    });

    function renderFinTable(rows, totals, familyUid, yearId) {
        const $body = $('#olama-reg-fin-body');
        $body.empty();
        rows.forEach(row => $body.append(buildFinRow(row)));
        updateTotals(totals);
        initDatepickers($body);
    }

    function buildFinRow(row) {
        const bal = ((parseFloat(row.amount_due) || 0) - (parseFloat(row.amount_paid) || 0) + (parseFloat(row.payments_revolving) || 0)).toFixed(2);
        return `
        <tr data-fin-id="${row.id}" class="olama-reg-fin-row">
            <td><input type="text" name="entitlement_date" value="${row.entitlement_date || ''}" class="olama-reg-inline-input olama-reg-datepicker"></td>
            <td><input type="text" name="calculation_method" value="${row.calculation_method || ''}" class="olama-reg-inline-input"></td>
            <td><input type="number" name="percentage" value="${row.percentage || ''}" class="olama-reg-inline-input" step="0.01"></td>
            <td><input type="number" name="amount_due" value="${row.amount_due || '0.00'}" class="olama-reg-inline-input olama-reg-fin-calc" step="0.01"></td>
            <td><input type="number" name="amount_paid" value="${row.amount_paid || '0.00'}" class="olama-reg-inline-input olama-reg-fin-calc" step="0.01"></td>
            <td><input type="number" name="payments_revolving" value="${row.payments_revolving || '0.00'}" class="olama-reg-inline-input olama-reg-fin-calc" step="0.01"></td>
            <td class="olama-reg-fin-balance olama-reg-balance-cell">${bal}</td>
            <td><input type="text" name="payment_reference" value="${row.payment_reference || ''}" class="olama-reg-inline-input"></td>
            <td><input type="text" name="fin_notes" value="${row.fin_notes || ''}" class="olama-reg-inline-input"></td>
            <td><button type="button" class="button button-small olama-reg-save-fin-row">حفظ</button></td>
            <td><button type="button" class="button button-small olama-reg-delete-fin-row" style="color:#c0392b">حذف</button></td>
        </tr>`;
    }

    // Add empty financial row
    $(document).on('click', '#olama-reg-add-fin-row', function () {
        const emptyRow = { id: 0, entitlement_date: '', calculation_method: '', percentage: '', amount_due: '0.00', amount_paid: '0.00', payments_revolving: '0.00', payment_reference: '', fin_notes: '' };
        const $row = $(buildFinRow(emptyRow));
        $('#olama-reg-fin-body').append($row);
        initDatepickers($row);
        $row[0].scrollIntoView({ behavior: 'smooth' });
    });

    // Live balance calculation
    $(document).on('input', '.olama-reg-fin-calc', function () {
        const $tr = $(this).closest('tr');
        const due = parseFloat($tr.find('[name="amount_due"]').val()) || 0;
        const paid = parseFloat($tr.find('[name="amount_paid"]').val()) || 0;
        const revolving = parseFloat($tr.find('[name="payments_revolving"]').val()) || 0;
        $tr.find('.olama-reg-balance-cell').text((due - paid + revolving).toFixed(2));
    });

    // Save financial row
    $(document).on('click', '.olama-reg-save-fin-row', function () {
        const $btn = $(this);
        const $tr = $btn.closest('tr');
        const familyUid = $('#olama-reg-family-uid').val();
        const yearId = $('#olama-reg-fin-year').val() || 0;
        const id = parseInt($tr.data('fin-id')) || 0;

        const data = {
            family_uid: familyUid,
            academic_year_id: yearId,
            id,
            entitlement_date: $tr.find('[name="entitlement_date"]').val(),
            calculation_method: $tr.find('[name="calculation_method"]').val(),
            percentage: $tr.find('[name="percentage"]').val(),
            amount_due: $tr.find('[name="amount_due"]').val(),
            amount_paid: $tr.find('[name="amount_paid"]').val(),
            payments_revolving: $tr.find('[name="payments_revolving"]').val(),
            payment_reference: $tr.find('[name="payment_reference"]').val(),
            fin_notes: $tr.find('[name="fin_notes"]').val(),
        };

        ajax('olama_reg_save_financial_row', data, $btn)
            .done(res => {
                if (res.success) {
                    if (!id && res.data.id) $tr.attr('data-fin-id', res.data.id);
                    showNotice(R.strings.saved);
                    if (res.data.totals) updateTotals(res.data.totals);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    // Delete financial row
    $(document).on('click', '.olama-reg-delete-fin-row', function () {
        if (!confirm(R.strings.confirmDelete)) return;
        const $tr = $(this).closest('tr');
        const id = parseInt($tr.data('fin-id')) || 0;
        const familyUid = $('#olama-reg-family-uid').val();
        const yearId = $('#olama-reg-fin-year').val() || 0;

        if (!id) { $tr.remove(); return; }

        ajax('olama_reg_delete_financial_row', { id, family_uid: familyUid, academic_year_id: yearId })
            .done(res => {
                if (res.success) {
                    $tr.remove();
                    showNotice(R.strings.saved);
                    if (res.data.totals) updateTotals(res.data.totals);
                }
            });
    });

    function updateTotals(t) {
        $('#total-due').text(parseFloat(t.total_due || 0).toFixed(2));
        $('#total-paid').text(parseFloat(t.total_paid || 0).toFixed(2));
        $('#total-revolving').text(parseFloat(t.total_revolving || 0).toFixed(2));
        $('#total-balance').text(parseFloat(t.total_balance || 0).toFixed(2));
    }

    // ── Academic History ──────────────────────────────────────────────────────

    $(document).on('click', '.olama-reg-add-hist-row', function () {
        const studentUid = $(this).data('student-uid');
        const $tbody = $(`.olama-reg-history-body[data-student-uid="${studentUid}"]`);
        $tbody.append(`
        <tr data-hist-id="0">
            <td><input type="text" name="academic_year" class="olama-reg-inline-input"></td>
            <td><input type="text" name="school_name" class="olama-reg-inline-input"></td>
            <td><input type="text" name="grade" class="olama-reg-inline-input"></td>
            <td><input type="text" name="branch" class="olama-reg-inline-input"></td>
            <td><input type="text" name="section" class="olama-reg-inline-input"></td>
            <td><input type="text" name="registration_date" class="olama-reg-inline-input olama-reg-datepicker"></td>
            <td><input type="text" name="withdrawal_date" class="olama-reg-inline-input olama-reg-datepicker"></td>
            <td><input type="text" name="student_status" class="olama-reg-inline-input"></td>
            <td><input type="checkbox" name="is_current" value="1"></td>
            <td><button type="button" class="button button-small olama-reg-save-hist-row">حفظ</button></td>
            <td><button type="button" class="button button-small olama-reg-delete-hist-row" style="color:red">حذف</button></td>
        </tr>`);
        initDatepickers($tbody);
    });

    $(document).on('click', '.olama-reg-save-hist-row', function () {
        const $btn = $(this);
        const $tr = $btn.closest('tr');
        const $tbody = $tr.closest('.olama-reg-history-body');
        const studentUid = $tbody.data('student-uid');
        const id = parseInt($tr.data('hist-id')) || 0;

        const data = {
            student_uid: studentUid,
            id,
            academic_year: $tr.find('[name="academic_year"]').val(),
            school_name: $tr.find('[name="school_name"]').val(),
            grade: $tr.find('[name="grade"]').val(),
            branch: $tr.find('[name="branch"]').val(),
            section: $tr.find('[name="section"]').val(),
            registration_date: $tr.find('[name="registration_date"]').val(),
            withdrawal_date: $tr.find('[name="withdrawal_date"]').val(),
            student_status: $tr.find('[name="student_status"]').val(),
            is_current: $tr.find('[name="is_current"]').is(':checked') ? 1 : 0,
        };

        ajax('olama_reg_save_academic_history', data, $btn)
            .done(res => {
                if (res.success) {
                    if (!id && res.data.id) $tr.attr('data-hist-id', res.data.id);
                    showNotice(R.strings.saved);
                } else showNotice(res.data?.message || R.strings.error, true);
            });
    });

    $(document).on('click', '.olama-reg-delete-hist-row', function () {
        if (!confirm(R.strings.confirmDelete)) return;
        const $tr = $(this).closest('tr');
        const id = parseInt($tr.data('hist-id')) || 0;
        if (!id) { $tr.remove(); return; }
        ajax('olama_reg_delete_history_row', { id })
            .done(res => { if (res.success) $tr.remove(); });
    });
    // ── Billing - Fee Templates ──────────────────────────────────────────────

    function calculateFeeTotals() {
        let total = 0;
        $('.olama-reg-fee-amount-input').each(function () {
            total += parseFloat($(this).val()) || 0;
        });
        $('#olama-reg-fee-total-label').text(total.toFixed(2));
    }

    function syncFeeTemplateSubject() {
        const type = $('#subject_type').val() || 'service';
        const $activeSelect = $(`.fee-template-subject-value[data-subject-type="${type}"]`);
        const value = $activeSelect.val() || '';
        const supportsInstallments = type === 'agreement' && String($activeSelect.find(':selected').data('has-installments')) !== '0';

        $('.fee-template-subject-value, .fee-template-subject-label').hide();
        $activeSelect.show().prop('required', true);
        $('.fee-template-subject-value').not($activeSelect).prop('required', false);
        $(`.fee-template-subject-label--${type}`).show();
        $('#subject_value').val(value);

        if (supportsInstallments) {
            $('#fee-template-installments-field').show();
        } else {
            $('#fee-template-installments-field').hide();
            $('#installments').val(1);
        }
    }

    $(document).on('change', '#subject_type, .fee-template-subject-value', syncFeeTemplateSubject);
    $(syncFeeTemplateSubject);

    function reindexFeeItems() {
        const $tbody = $('#olama-reg-fee-items-table tbody');
        const $rows = $tbody.find('tr');
        $rows.each(function (idx) {
            $(this).find('input[name*="[description]"]').attr('name', `items[${idx}][description]`);
            $(this).find('input[name*="[amount]"]').attr('name', `items[${idx}][amount]`);
        });
        calculateFeeTotals();
    }

    $(document).on('click', '#olama-reg-add-fee-row-btn', function () {
        const $tbody = $('#olama-reg-fee-items-table tbody');
        const newRow = `
        <tr>
            <td>
                <input type="text" name="items[temp][description]" class="olama-reg-inline-input" required placeholder="مثال: رسوم التسجيل، رسوم الباص...">
            </td>
            <td>
                <input type="number" step="0.01" name="items[temp][amount]" value="0.00" class="olama-reg-inline-input olama-reg-fee-amount-input" required>
            </td>
            <td>
                <button type="button" class="button button-small olama-reg-remove-fee-row-btn" style="color:#c62828;">x</button>
            </td>
        </tr>`;
        $tbody.append(newRow);
        reindexFeeItems();
    });

    $(document).on('click', '.olama-reg-remove-fee-row-btn', function () {
        const $tbody = $('#olama-reg-fee-items-table tbody');
        if ($tbody.find('tr').length > 1) {
            $(this).closest('tr').remove();
            reindexFeeItems();
        } else {
            alert('يجب أن يحتوي نموذج الرسوم على بند واحد على الأقل.');
        }
    });

    $(document).on('input change', '.olama-reg-fee-amount-input', function () {
        calculateFeeTotals();
    });

    $(document).on('submit', '#olama-reg-fee-template-form', function (e) {
        e.preventDefault();
        syncFeeTemplateSubject();

        const $btn = $('#olama-reg-save-fee-template-btn');
        const formData = {};
        $(this).find('[name]').each(function () {
            formData[this.name] = $(this).val();
        });

        ajax('olama_reg_save_fee_template', formData, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(R.strings.saved);
                    setTimeout(() => {
                        window.location.href = R.ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=olama-registration-fees';
                    }, 1000);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    $(document).on('click', '.olama-reg-delete-fee-template-btn', function () {
        if (!confirm(R.strings.confirmDelete)) return;
        const $btn = $(this);
        const id = $btn.data('id');
        const $tr = $btn.closest('tr');
        ajax('olama_reg_delete_fee_template', { id }, $btn)
            .done(res => {
                if (res.success) {
                    $tr.fadeOut(300, function () { $(this).remove(); });
                    showNotice(R.strings.saved);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    // ── Billing - Invoices & Modal ───────────────────────────────────────────

    function calculateInvoiceTotals() {
        let subtotal = 0;
        $('#olama-reg-invoice-items-table tbody tr').not('.olama-reg-empty-items-row').each(function () {
            const qty = parseFloat($(this).find('.inv-item-qty').val()) || 0;
            const price = parseFloat($(this).find('.inv-item-price').val()) || 0;
            const lineTotal = qty * price;
            $(this).find('.inv-item-line-total').text(lineTotal.toFixed(2));
            subtotal += lineTotal;
        });

        const discount = parseFloat($('#inv-discount-input').val()) || 0;
        const grandTotal = Math.max(0, subtotal - discount);

        $('#inv-subtotal-label').text(subtotal.toFixed(2));
        $('#inv-grand-total-label').text(grandTotal.toFixed(2));
    }

    function reindexInvoiceItems() {
        const $tbody = $('#olama-reg-invoice-items-table tbody');
        const $rows = $tbody.find('tr').not('.olama-reg-empty-items-row');
        if ($rows.length === 0) {
            $tbody.find('.olama-reg-empty-items-row').show();
        } else {
            $tbody.find('.olama-reg-empty-items-row').hide();
            $rows.each(function (idx) {
                $(this).find('.inv-item-desc').attr('name', `items[${idx}][description]`);
                $(this).find('.inv-item-qty').attr('name', `items[${idx}][quantity]`);
                $(this).find('.inv-item-price').attr('name', `items[${idx}][unit_price]`);
            });
        }
        calculateInvoiceTotals();
    }

    function optionMatchesSubject(option, expectedType, expectedValue, includeGeneral = true) {
        const $opt = $(option);
        const type = String($opt.data('subject-type') || 'general');
        const value = String($opt.data('subject-value') || '');

        if (!$opt.val()) return true;
        if (type === 'general') return includeGeneral;
        return type === expectedType && value === String(expectedValue || '');
    }

    function filterTemplateSelect($select, expectedType, expectedValue, keepSelected = false, includeGeneral = true) {
        const currentValue = $select.val();
        let currentStillVisible = false;

        $select.find('option').each(function () {
            const isCurrent = currentValue && String($(this).val()) === String(currentValue);
            const shouldShow = optionMatchesSubject(this, expectedType, expectedValue, includeGeneral) || (keepSelected && isCurrent);
            $(this).prop('hidden', !shouldShow).prop('disabled', !shouldShow);
            if (isCurrent && shouldShow) currentStillVisible = true;
        });

        if (currentValue && !currentStillVisible) {
            $select.val('');
        }
    }

    function syncInvoiceTemplateOptions() {
        const serviceType = $('#inv_service_type').val() || '';
        filterTemplateSelect($('#inv_fee_template_id'), 'service', serviceType, false, false);
        $('#inv_installments').val(1);
    }

    function syncCustomPaymentTemplateOptions() {
        const serviceType = $('#cp_service_type').val() || '';
        filterTemplateSelect($('#cp_fee_template'), 'service', serviceType, false, false);
    }

    function getCurrentAgreementNature() {
        return $('#os-agr-activity-modal').val() || $('#os-agr-activity').val() || '';
    }

    function syncAgreementFeeTemplateOptions(scope) {
        const nature = getCurrentAgreementNature();
        const $scope = scope ? $(scope) : $(document);
        $scope.find('.os-agr-fee-template-select').each(function () {
            filterTemplateSelect($(this), 'agreement', nature, true);
        });
    }
    window.olamaRegSyncAgreementFeeTemplateOptions = syncAgreementFeeTemplateOptions;

    function initEmbeddedAgreementFeeRowSelects($row) {
        if (typeof $.fn.select2 === 'undefined') return;

        const $modal = $row.closest('#os-hub-original-form-modal');
        if (!$modal.length) return;

        $row.find('select').each(function () {
            const $select = $(this);
            if (!$select.hasClass('select2-hidden-accessible') && !$select.prop('disabled')) {
                $select.select2({
                    dir: 'rtl',
                    width: '100%',
                    dropdownParent: $modal
                });
            }
        });
    }

    function initStatementFamilySearch() {
        const $entityType = $('#olama-reg-statement-entity-type');
        const $familyField = $('#olama-reg-statement-family-field');
        const $family = $('#olama-reg-statement-family');
        const $externalField = $('#olama-reg-statement-external-field');
        const $external = $('#olama-reg-statement-external');

        if (!$entityType.length || !$family.length) return;

        const syncEntityFields = function () {
            const isFamily = $entityType.val() === 'family';
            $familyField.prop('hidden', !isFamily);
            $family.prop('disabled', !isFamily);
            $externalField.prop('hidden', isFamily);
            $external.prop('disabled', isFamily);
        };

        if (typeof $.fn.select2 !== 'undefined' && !$family.hasClass('select2-hidden-accessible')) {
            $family.select2({
                dir: 'rtl',
                width: '100%',
                allowClear: true,
                placeholder: $family.data('placeholder'),
                minimumInputLength: 1,
                ajax: {
                    url: R.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    type: 'POST',
                    data: function (params) {
                        return {
                            action: 'olama_reg_search',
                            nonce: R.nonce,
                            q: params.term || ''
                        };
                    },
                    processResults: function (response) {
                        const families = response?.success && response.data?.families
                            ? response.data.families
                            : [];

                        return {
                            results: families.map(function (family) {
                                const familyNumber = String(family.family_uid || '').replace(/^ORA-FAM-/, '');
                                const name = [family.father_first_name, family.father_family_name]
                                    .filter(Boolean)
                                    .join(' ')
                                    .trim();

                                return {
                                    id: familyNumber,
                                    text: familyNumber + (name ? ' — ' + name : '')
                                };
                            })
                        };
                    },
                    cache: true
                }
            });
        }

        $entityType.on('change', syncEntityFields);
        syncEntityFields();
    }

    $(document).on('change', '#inv_service_type', syncInvoiceTemplateOptions);
    $(document).on('change', '#cp_service_type', syncCustomPaymentTemplateOptions);
    $(document).on('change', '#os-agr-activity, #os-agr-activity-modal', function () {
        syncAgreementFeeTemplateOptions();
    });

    $(function () {
        syncInvoiceTemplateOptions();
        syncCustomPaymentTemplateOptions();
        syncAgreementFeeTemplateOptions();
        initStatementFamilySearch();
    });

    $(document).on('click', '#olama-reg-open-invoice-modal-btn', function () {
        $('#olama-reg-invoice-modal').fadeIn(200);
        syncInvoiceTemplateOptions();

        // Select2 for family select with AJAX search
        if (typeof $.fn.select2 !== 'undefined' && $('#inv_family_uid').length && !$('#inv_family_uid').hasClass('select2-hidden-accessible')) {
            $('#inv_family_uid').select2({
                dir: 'rtl',
                width: '100%',
                dropdownParent: $('#olama-reg-invoice-modal'),
                ajax: {
                    url: R.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    type: 'POST',
                    data: function (params) {
                        return {
                            action: 'olama_reg_search',
                            nonce: R.nonce,
                            q: params.term
                        };
                    },
                    processResults: function (data) {
                        if (data.success && data.data.families) {
                            return {
                                results: data.data.families.map(f => {
                                    const name1 = f.father_first_name || '';
                                    const name2 = f.father_family_name || '';
                                    const fullName = (name1 + ' ' + name2).trim() || 'بدون اسم';
                                    return {
                                        id: f.family_uid,
                                        text: `${fullName} (${f.family_uid})`
                                    };
                                })
                            };
                        }
                        return { results: [] };
                    },
                    cache: true
                },
                placeholder: 'ابحث عن عائلة باستخدام رقم الملف أو الاسم...',
                minimumInputLength: 2
            });
        }
    });

    $(document).on('click', '.olama-reg-modal-close', function () {
        $(this).closest('.olama-reg-modal').fadeOut(200);
    });

    $(document).on('change', '#inv_family_uid', function () {
        const familyUid = $(this).val();
        const $studentSelect = $('#inv_student_uid');
        $studentSelect.empty().append('<option value="">فاتورة عامة للعائلة</option>');
        if (!familyUid) return;

        ajax('olama_reg_get_family', { family_uid: familyUid })
            .done(res => {
                if (res.success && res.data.students) {
                    res.data.students.forEach(s => {
                        $studentSelect.append(`<option value="${s.student_uid}">${s.student_name} (${s.student_uid})</option>`);
                    });
                }
            });
    });

    $(document).on('change', '#inv_fee_template_id', function () {
        const $opt = $(this).find('option:selected');
        const inst = parseInt($opt.data('inst')) || 1;
        const itemsRaw = $opt.data('items');

        $('#inv_installments').val(1);

        const $tbody = $('#olama-reg-invoice-items-table tbody');
        $tbody.find('tr').not('.olama-reg-empty-items-row').remove();

        let items = [];
        if (typeof itemsRaw === 'string') {
            try { items = JSON.parse(itemsRaw); } catch (e) { }
        } else if (Array.isArray(itemsRaw)) {
            items = itemsRaw;
        }

        if (items.length) {
            items.forEach((item, idx) => {
                const row = `
                <tr>
                    <td>
                        <input type="text" name="items[${idx}][description]" value="${item.description}" class="olama-reg-inline-input inv-item-desc" required>
                    </td>
                    <td>
                        <input type="number" name="items[${idx}][quantity]" value="1" min="1" class="olama-reg-inline-input inv-item-qty" style="width:70px; text-align:center;" required>
                    </td>
                    <td>
                        <input type="number" step="0.01" name="items[${idx}][unit_price]" value="${parseFloat(item.amount).toFixed(2)}" class="olama-reg-inline-input inv-item-price" style="width:110px;" required>
                    </td>
                    <td>
                        <span class="inv-item-line-total" style="font-weight:700;">${parseFloat(item.amount).toFixed(2)}</span>
                    </td>
                    <td>
                        <button type="button" class="button button-small inv-remove-item-row-btn" style="color:#c62828;">x</button>
                    </td>
                </tr>`;
                $tbody.append(row);
            });
        }

        reindexInvoiceItems();
    });

    $(document).on('click', '#inv-add-item-row-btn', function () {
        const $tbody = $('#olama-reg-invoice-items-table tbody');
        const idx = $tbody.find('tr').not('.olama-reg-empty-items-row').length;
        const row = `
        <tr>
            <td>
                <input type="text" name="items[${idx}][description]" class="olama-reg-inline-input inv-item-desc" placeholder="بند رسوم مخصص..." required>
            </td>
            <td>
                <input type="number" name="items[${idx}][quantity]" value="1" min="1" class="olama-reg-inline-input inv-item-qty" style="width:70px; text-align:center;" required>
            </td>
            <td>
                <input type="number" step="0.01" name="items[${idx}][unit_price]" value="0.00" class="olama-reg-inline-input inv-item-price" style="width:110px;" required>
            </td>
            <td>
                <span class="inv-item-line-total" style="font-weight:700;">0.00</span>
            </td>
            <td>
                <button type="button" class="button button-small inv-remove-item-row-btn" style="color:#c62828;">x</button>
            </td>
        </tr>`;
        $tbody.append(row);
        reindexInvoiceItems();
    });

    $(document).on('click', '.inv-remove-item-row-btn', function () {
        $(this).closest('tr').remove();
        reindexInvoiceItems();
    });

    $(document).on('input change', '.inv-item-qty, .inv-item-price', function () {
        calculateInvoiceTotals();
    });

    $(document).on('input change', '#inv-discount-input', function () {
        calculateInvoiceTotals();
    });

    $(document).on('submit', '#olama-reg-invoice-form', function (e) {
        e.preventDefault();
        const $tbody = $('#olama-reg-invoice-items-table tbody');
        if ($tbody.find('tr').not('.olama-reg-empty-items-row').length === 0) {
            alert('يرجى إضافة بند واحد على الأقل للفاتورة.');
            return;
        }

        const $btn = $('#olama-reg-save-invoice-btn');
        const formData = {};
        $(this).find('[name]').each(function () {
            formData[this.name] = $(this).val();
        });

        ajax('olama_reg_create_invoice', formData, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(R.strings.saved);
                    if ($('#os-hub-data').length) {
                        $('#olama-reg-invoice-modal').fadeOut(200);
                        $(document).trigger('osHub:invoiceSaved', [res.data]);
                    } else {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Billing - Cancel & Reverse ──────────────────────────────────────────

    $(document).on('click', '.olama-reg-cancel-invoice-btn', function (e) {
        e.preventDefault();
        if (!confirm('هل أنت متأكد من إلغاء هذه الفاتورة؟ لا يمكن التراجع عن هذا الإجراء.')) return;

        const id = $(this).data('id');
        const $btn = $(this);

        ajax('olama_reg_cancel_invoice', { id }, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    $(document).on('click', '.olama-reg-reverse-payment-btn', function (e) {
        e.preventDefault();
        const notes = prompt('الرجاء إدخال سبب عكس السند (اختياري):', 'عكس سند بالخطأ');
        if (notes === null) return; // User cancelled prompt

        const id = $(this).data('id');
        const $btn = $(this);

        ajax('olama_reg_reverse_payment', { id, notes }, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Billing - Invoice Details Drawer ─────────────────────────────────────

    $(document).on('click', '.olama-reg-confirm-payment-btn, .olama-reg-reject-payment-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const id = $btn.data('id');
        const isConfirm = $btn.hasClass('olama-reg-confirm-payment-btn');
        const notes = prompt(isConfirm ? 'ملاحظات الاعتماد (اختياري):' : 'سبب الرفض (اختياري):', '');
        if (notes === null) return;

        ajax(isConfirm ? 'olama_reg_confirm_payment' : 'olama_reg_reject_payment', { id, notes }, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    $(document).on('click', '.olama-reg-cheque-action-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const id = $btn.data('id');
        const cheque_action = $btn.data('action');
        const notes = prompt('ملاحظات الشيك (اختياري):', '');
        if (notes === null) return;

        ajax('olama_reg_update_cheque_status', { id, cheque_action, notes }, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    $(document).on('click', '.olama-reg-view-invoice-btn', function () {
        const id = $(this).data('id');
        const $btn = $(this);

        ajax('olama_reg_get_invoice', { id }, $btn)
            .done(res => {
                if (res.success && res.data.invoice) {
                    const inv = res.data.invoice;

                    $('#drawer-invoice-number').text(inv.invoice_number);
                    $('#drawer-total-val').text(parseFloat(inv.total).toFixed(2) + ' د.أ');
                    $('#drawer-discount-val').text(parseFloat(inv.discount || 0).toFixed(2) + ' د.أ');
                    $('#drawer-paid-val').text(parseFloat(inv.amount_paid).toFixed(2) + ' د.أ');
                    $('#drawer-balance-val').text(parseFloat(inv.balance).toFixed(2) + ' د.أ');
                    $('#drawer-debit-notes-val').text(parseFloat(inv.debit_notes_total || 0).toFixed(2) + ' د.أ');
                    $('#drawer-credit-notes-val').text(parseFloat(inv.credit_notes_total || 0).toFixed(2) + ' د.أ');
                    $('#drawer-family-uid').text(inv.family_uid);
                    $('#drawer-issue-date').text(inv.issue_date);
                    $('#drawer-due-date').text(inv.due_date || '—');

                    let statusClass = 'olama-reg-badge--inactive';
                    let statusLabel = 'مسودة';
                    switch (inv.status) {
                        case 'issued':
                            statusClass = 'olama-reg-badge--active';
                            statusLabel = 'صادرة';
                            break;
                        case 'partial':
                            statusClass = 'olama-reg-badge--active';
                            statusLabel = 'جزئية';
                            break;
                        case 'paid':
                            statusClass = 'olama-reg-badge--active';
                            statusLabel = 'مدفوعة';
                            break;
                        case 'overdue':
                            statusClass = 'olama-reg-badge--blacklist';
                            statusLabel = 'متأخرة';
                            break;
                        case 'cancelled':
                            statusClass = 'olama-reg-badge--inactive';
                            statusLabel = 'ملغاة';
                            break;
                    }
                    const overdueBadge = inv.is_overdue && inv.status !== 'overdue'
                        ? ' <span class="olama-reg-badge olama-reg-badge--blacklist">متأخرة</span>'
                        : '';
                    $('#drawer-status-badge').html(`<span class="olama-reg-badge ${statusClass}">${statusLabel}</span>${overdueBadge}`);

                    // Populate items
                    const $itemsBody = $('#drawer-items-table tbody');
                    $itemsBody.empty();
                    if (inv.items && inv.items.length) {
                        inv.items.forEach(item => {
                            $itemsBody.append(`
                            <tr>
                                <td>${item.description}</td>
                                <td style="text-align:center;">${parseFloat(item.quantity).toFixed(0)}</td>
                                <td>${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td style="font-weight:700;">${parseFloat(item.line_total).toFixed(2)}</td>
                            </tr>`);
                        });
                    }

                    // Populate installments
                    const $instBody = $('#drawer-installments-table tbody');
                    $instBody.empty();
                    if (inv.installments && inv.installments.length) {
                        inv.installments.forEach(inst => {
                            let instStatusClass = 'olama-reg-badge--inactive';
                            let instStatusLabel = 'معلق';
                            switch (inst.status) {
                                case 'unpaid':
                                case 'pending':
                                    instStatusClass = 'olama-reg-badge--inactive';
                                    instStatusLabel = 'غير مسدد';
                                    break;
                                case 'paid':
                                    instStatusClass = 'olama-reg-badge--active';
                                    instStatusLabel = 'مسدد';
                                    break;
                                case 'partially_paid':
                                case 'partial':
                                    instStatusClass = 'olama-reg-badge--active';
                                    instStatusLabel = 'جزئي';
                                    break;
                                case 'overdue':
                                    instStatusClass = 'olama-reg-badge--blacklist';
                                    instStatusLabel = 'متأخر';
                                    break;
                            }

                            $instBody.append(`
                            <tr>
                                <td>${inst.installment_no}</td>
                                <td>${inst.due_date}</td>
                                <td style="font-weight:700;">${parseFloat(inst.amount_due).toFixed(2)}</td>
                                <td>${parseFloat(inst.amount_paid).toFixed(2)}</td>
                                <td><span class="olama-reg-badge ${instStatusClass}">${instStatusLabel}</span></td>
                            </tr>`);
                        });
                    } else {
                        $instBody.append('<tr><td colspan="5" style="text-align:center; color:#6b7280;">لا يوجد جدول استحقاق مرتبط بهذه الفاتورة.</td></tr>');
                    }

                    // Populate payments (سجل الدفعات السابقة)
                    const $paySection = $('#drawer-payments-section');
                    const $payBody = $('#drawer-payments-table tbody');
                    $payBody.empty();
                    
                    if (inv.payments && inv.payments.length) {
                        const $payHeadRow = $('#drawer-payments-table thead tr');
                        if ($payHeadRow.length && $payHeadRow.find('.drawer-payment-status-head').length === 0) {
                            $('<th class="drawer-payment-status-head">حالة الدفعة</th>').insertAfter($payHeadRow.find('th').eq(2));
                        }

                        inv.payments.forEach(pay => {
                            let methodLabel = 'أخرى';
                            switch(pay.method) {
                                case 'cash': methodLabel = 'نقدي'; break;
                                case 'bank_transfer': methodLabel = 'حوالة بنكية'; break;
                                case 'cheque': methodLabel = 'شيك'; break;
                                case 'online': methodLabel = 'دفع إلكتروني'; break;
                                case 'reversal': methodLabel = 'عكس سند'; break;
                            }

                            let paymentStatusLabel = 'معتمد';
                            let paymentStatusClass = 'olama-reg-badge--active';
                            switch (pay.status) {
                                case 'draft':
                                    paymentStatusLabel = 'مسودة';
                                    paymentStatusClass = 'olama-reg-badge--inactive';
                                    break;
                                case 'pending_review':
                                    paymentStatusLabel = 'قيد المراجعة';
                                    paymentStatusClass = 'olama-reg-badge--warning';
                                    break;
                                case 'reversed':
                                    paymentStatusLabel = 'معكوس';
                                    paymentStatusClass = 'olama-reg-badge--blacklist';
                                    break;
                                case 'failed':
                                    paymentStatusLabel = 'فشل';
                                    paymentStatusClass = 'olama-reg-badge--blacklist';
                                    break;
                                case 'cancelled':
                                    paymentStatusLabel = 'ملغى';
                                    paymentStatusClass = 'olama-reg-badge--inactive';
                                    break;
                                default:
                                    paymentStatusLabel = 'معتمد';
                                    paymentStatusClass = 'olama-reg-badge--active';
                                    break;
                            }
                            const isReversal = (pay.method === 'reversal');
                            const color = isReversal ? '#dc2626' : '#16a34a';
                            const formattedAmount = parseFloat(pay.amount).toFixed(2);
                            
                            $payBody.append(`
                            <tr>
                                <td><strong>#${pay.id}</strong></td>
                                <td>${pay.payment_date}</td>
                                <td>${methodLabel}</td>
                                <td><span class="olama-reg-badge ${paymentStatusClass}">${paymentStatusLabel}</span></td>
                                <td style="font-weight:800; color:${color};">${formattedAmount}</td>
                                <td style="font-size:13px; color:#6B7280;">${pay.reference || '—'}</td>
                            </tr>`);
                        });
                        $paySection.show();
                    } else {
                        $paySection.hide();
                    }

                    let $activitySection = $('#drawer-activity-section');
                    if (!$activitySection.length) {
                        $activitySection = $(`
                            <div class="olama-reg-section" id="drawer-activity-section">
                                <h3 class="olama-reg-section-title">حركات الفاتورة</h3>
                                <div class="olama-reg-table-wrap">
                                    <table class="olama-reg-fin-table" id="drawer-activity-table">
                                        <thead>
                                            <tr>
                                                <th>التاريخ</th>
                                                <th>نوع الحركة</th>
                                                <th>الرقم المرجعي</th>
                                                <th>المستخدم</th>
                                                <th>المبلغ</th>
                                                <th>الوصف</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        `);
                        $('#drawer-payments-section').after($activitySection);
                    }
                    const $activityBody = $('#drawer-activity-table tbody');
                    $activityBody.empty();
                    if (inv.activity && inv.activity.length) {
                        inv.activity.forEach(ev => {
                            const amount = ev.amount === '' || ev.amount === null ? '—' : parseFloat(ev.amount).toFixed(2);
                            $activityBody.append(`
                                <tr>
                                    <td>${ev.date || '—'}</td>
                                    <td>${ev.type || '—'}</td>
                                    <td>${ev.reference || '—'}</td>
                                    <td>${ev.user || '—'}</td>
                                    <td>${amount}</td>
                                    <td>${ev.description || '—'}</td>
                                </tr>
                            `);
                        });
                    } else {
                        $activityBody.append('<tr><td colspan="6" style="text-align:center; color:#6b7280;">لا توجد حركات مسجلة.</td></tr>');
                    }

                    // Payment record trigger
                    const $drawerActions = $('#olama-reg-invoice-drawer').find('.olama-reg-form-actions');
                    $drawerActions.find('.olama-reg-pay-invoice-trigger, .olama-reg-adjustment-trigger').remove();
                    const policy = inv.policy || {};
                    if (policy.can_create_debit_note) {
                        $drawerActions.prepend(`
                            <button type="button" class="button olama-reg-adjustment-trigger" data-type="debit" data-id="${inv.id}">
                                إشعار مدين
                            </button>
                        `);
                    }
                    if (policy.can_create_credit_note) {
                        $drawerActions.prepend(`
                            <button type="button" class="button olama-reg-adjustment-trigger" data-type="credit" data-id="${inv.id}">
                                إشعار دائن
                            </button>
                        `);
                    }
                    if (policy.can_record_payment) {
                        $drawerActions.prepend(`
                            <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-reg-pay-invoice-trigger" 
                                    data-id="${inv.id}" data-no="${inv.invoice_number}" data-bal="${inv.balance}" data-family="${inv.family_uid}">
                                تسجيل دفعة ماليّة
                            </button>
                        `);
                    }

                    $('#olama-reg-invoice-drawer').fadeIn(200);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    $(document).on('click', '.olama-reg-drawer-close', function () {
        $('#olama-reg-invoice-drawer').fadeOut(200);
    });

    $(document).on('click', '.olama-reg-adjustment-trigger', function () {
        const $btn = $(this);
        const invoiceId = $btn.data('id');
        const type = $btn.data('type');
        const label = type === 'credit' ? 'الإشعار الدائن' : 'الإشعار المدين';
        const amount = prompt(`أدخل قيمة ${label}`);
        if (amount === null) return;
        const parsedAmount = parseMoney(amount);
        if (parsedAmount <= 0) {
            showNotice('يجب أن تكون قيمة الإشعار أكبر من صفر.', true);
            return;
        }
        const reason = prompt(`أدخل سبب ${label}`);
        if (reason === null) return;
        if (!String(reason).trim()) {
            showNotice('يجب إدخال سبب الإشعار المالي.', true);
            return;
        }

        ajax('olama_reg_create_invoice_adjustment', {
            invoice_id: invoiceId,
            type,
            amount: parsedAmount,
            reason
        }, $btn).done(res => {
            if (res.success) {
                showNotice(res.data.message);
                setTimeout(() => window.location.reload(), 700);
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(() => showNotice(R.strings.error, true));
    });

    // ── Billing - Payments & Receipts ────────────────────────────────────────

    const paymentModalHtml = `
    <div id="olama-reg-payment-modal" class="olama-reg-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
        <div class="olama-reg-wrap" style="margin:0 auto !important; padding:40px 10px !important; max-width:600px !important; width:100% !important;">
            <div class="olama-reg-modal-dialog" style="margin:0 !important; max-width:none !important;">
                <div class="olama-reg-modal-header">
                    <h2 class="olama-reg-modal-title">
                        <span class="dashicons dashicons-money-alt"></span>
                        تسجيل دفعة جديدة
                    </h2>
                    <button type="button" class="olama-reg-pay-modal-close olama-reg-modal-close" style="border:none;background:none;color:#fff;font-size:24px;cursor:pointer;">&times;</button>
                </div>
                
                <form id="olama-reg-payment-form" style="margin:0;">
                    <div class="olama-reg-modal-body">
                        <input type="hidden" name="invoice_id" id="pay_invoice_id">
                        <input type="hidden" name="family_uid" id="pay_family_uid">
                        
                        <div class="olama-reg-section" id="pay_family_search_wrap" style="display:none;">
                            <h3 class="olama-reg-section-title">البحث والربط (دفعة عامة)</h3>
                            <div class="olama-reg-grid">
                                <div class="olama-reg-field">
                                    <label for="pay_search_family">بحث عن العائلة:</label>
                                    <select id="pay_search_family" style="width:100%;"></select>
                                </div>
                                <div class="olama-reg-field" id="pay_invoice_select_wrap" style="display:none;">
                                    <label for="pay_select_invoice">الفاتورة المستهدفة:</label>
                                    <select id="pay_select_invoice" style="width:100%;">
                                        <option value="">-- اختر الفاتورة --</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="olama-reg-section" id="pay_invoice_display_wrap">
                            <h3 class="olama-reg-section-title">معلومات الفاتورة المستهدفة</h3>
                            <div class="olama-reg-grid">
                                <div class="olama-reg-field" style="display:flex; flex-direction:column;">
                                    <label>رقم الفاتورة:</label>
                                    <span id="pay_invoice_no_lbl" style="font-weight:800; color:#1a1a2e; font-size:16px; margin-top:4px;"></span>
                                </div>
                                <div class="olama-reg-field" style="display:flex; flex-direction:column;">
                                    <label>المتبقي غير المدفوع:</label>
                                    <span id="pay_invoice_bal_lbl" style="font-weight:800; color:#E8920A; font-size:16px; margin-top:4px;">0.00 د.أ</span>
                                </div>
                            </div>
                        </div>

                        <div class="olama-reg-section">
                            <h3 class="olama-reg-section-title">تفاصيل الدفعة المالية</h3>
                            <div class="olama-reg-grid">
                                <div class="olama-reg-field olama-reg-field--required">
                                    <label for="pay_amount">أدخل قيمة الدفعة المقبوضة (د.أ)</label>
                                    <input type="number" step="0.01" min="0.01" value="0.00" placeholder="0.00" id="pay_amount" name="amount" required>
                                </div>
                                <div class="olama-reg-field olama-reg-field--required">
                                    <label for="pay_method">طريقة الدفع</label>
                                    <select id="pay_method" name="method" required>
                                        <option value="cash">نقدي (كاش)</option>
                                        <option value="bank_transfer">تحويل بنكي</option>
                                        <option value="cheque">شيك بنكي</option>
                                        <option value="online">دفع إلكتروني</option>
                                    </select>
                                </div>
                                <div class="olama-reg-field" id="pay_reference_wrap">
                                    <label for="pay_reference">رقم المرجع / الشيك</label>
                                    <input type="text" id="pay_reference" name="reference" placeholder="رقم المعاملة أو رقم الشيك...">
                                </div>
                                <div class="olama-reg-field olama-reg-field--required">
                                    <label for="pay_date">تاريخ القبض</label>
                                    <input type="text" id="pay_date" name="payment_date" class="olama-reg-datepicker" required>
                                </div>
                            </div>
                        </div>

                        <div class="olama-reg-section">
                            <h3 class="olama-reg-section-title">ملاحظات إضافية</h3>
                            <div style="padding:14px;">
                                <textarea id="pay_notes" name="notes" rows="3" style="width:100%; border:1.5px solid #E0C090; border-radius:6px; padding:8px; font-family:inherit;"></textarea>
                            </div>
                        </div>

                    </div>
                    
                    <div class="olama-reg-form-actions">
                        <button type="submit" class="olama-reg-btn olama-reg-btn--primary" id="olama-reg-save-payment-btn">حفظ وتسجيل السند</button>
                        <button type="button" class="button button-large olama-reg-pay-modal-close">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>`;

    $(document).on('click', '.olama-reg-pay-invoice-trigger', function () {
        const id = $(this).data('id');
        const no = $(this).data('no');
        const bal = parseFloat($(this).data('bal')) || 0;
        const family = $(this).data('family');

        $('#pay_family_search_wrap, #pay_invoice_select_wrap').hide();
        $('#pay_invoice_display_wrap').show();

        $('#pay_invoice_id').val(id);
        $('#pay_family_uid').val(family);
        $('#pay_invoice_no_lbl').text(no);
        $('#pay_invoice_bal_lbl').text(bal.toFixed(2) + ' د.أ');
        $('#pay_amount').val('0.00').attr({
            min: '0.01',
            max: bal.toFixed(2)
        });

        const today = new Date().toISOString().split('T')[0];
        $('#pay_date').val(today);

        $('#olama-reg-invoice-drawer').fadeOut(100);
        $('#olama-reg-payment-modal').fadeIn(200);
        initDatepickers($('#olama-reg-payment-modal'));
    });

    $(document).on('click', '#olama-reg-open-general-payment-btn', function () {
        $('#pay_family_search_wrap, #pay_invoice_select_wrap').show();
        $('#pay_invoice_display_wrap').hide();

        $('#pay_invoice_id').val('');
        $('#pay_family_uid').val('');
        $('#pay_invoice_bal_lbl').text('0.00 د.أ');
        $('#pay_amount').val('0.00').attr('min', '0.01').removeAttr('max');

        if (typeof $.fn.select2 !== 'undefined' && !$('#pay_search_family').hasClass('select2-hidden-accessible')) {
            $('#pay_search_family').select2({
                dir: 'rtl', width: '100%',
                dropdownParent: $('#olama-reg-payment-modal'),
                ajax: {
                    url: R.ajaxurl, type: 'POST', dataType: 'json', delay: 250,
                    data: function (params) { return { action: 'olama_reg_search', nonce: R.nonce, q: params.term }; },
                    processResults: function (data) {
                        return {
                            results: (data.success && data.data.families) ? data.data.families.map(f => {
                                const name1 = f.father_first_name || '';
                                const name2 = f.father_family_name || '';
                                const fullName = (name1 + ' ' + name2).trim() || 'بدون اسم';
                                return { id: f.family_uid, text: `${fullName} (${f.family_uid})` };
                            }) : []
                        };
                    }
                },
                placeholder: 'ابحث عن العائلة للوصول إلى فواتيرها...',
                minimumInputLength: 2
            });
        }

        const today = new Date().toISOString().split('T')[0];
        $('#pay_date').val(today);

        $('#olama-reg-payment-modal').fadeIn(200);
        initDatepickers($('#olama-reg-payment-modal'));
    });

    $(document).on('change', '#pay_search_family', function () {
        const familyUid = $(this).val();
        $('#pay_family_uid').val(familyUid);
        const $invSelect = $('#pay_select_invoice');
        $invSelect.empty().append('<option value="">جاري تحميل الفواتير...</option>');
        $('#pay_invoice_id').val('');
        $('#pay_invoice_bal_lbl').text('0.00 د.أ');
        $('#pay_amount').val('0.00').attr('min', '0.01').removeAttr('max');

        if (!familyUid) {
            $invSelect.empty().append('<option value="">-- اختر الفاتورة --</option>');
            return;
        }
        ajax('olama_reg_get_family_billing', { family_uid: familyUid, academic_year_id: 0 })
            .done(res => {
                $invSelect.empty().append('<option value="">-- اختر الفاتورة --</option>');
                if (res.success && res.data.invoices) {
                    res.data.invoices.forEach(inv => {
                        if (parseFloat(inv.balance) > 0 && inv.status !== 'cancelled' && inv.status !== 'draft') {
                            $invSelect.append(`<option value="${inv.id}" data-bal="${inv.balance}">${inv.invoice_number} (المتبقي: ${parseFloat(inv.balance).toFixed(2)})</option>`);
                        }
                    });
                }
            });
    });

    $(document).on('change', '#pay_select_invoice', function () {
        const id = $(this).val();
        $('#pay_invoice_id').val(id);
        const bal = parseFloat($(this).find(':selected').data('bal')) || 0;
        $('#pay_invoice_bal_lbl').text(bal.toFixed(2) + ' د.أ');
        const $amount = $('#pay_amount').val('0.00').attr('min', '0.01');
        if (id && bal > 0) {
            $amount.attr('max', bal.toFixed(2));
        } else {
            $amount.removeAttr('max');
        }
    });

    $(document).on('click', '.olama-reg-pay-modal-close', function () {
        $('#olama-reg-payment-modal').fadeOut(200);
    });

    $(document).on('submit', '#olama-reg-payment-form', function (e) {
        e.preventDefault();
        const $btn = $('#olama-reg-save-payment-btn');
        const formData = {};
        $(this).find('[name]').each(function () {
            formData[this.name] = $(this).val();
        });

        ajax('olama_reg_record_payment', formData, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(R.strings.saved);
                    if ($('#os-hub-data').length) {
                        $('#olama-reg-payment-modal').fadeOut(200);
                        $(document).trigger('osHub:paymentSaved', [res.data]);
                    } else {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Billing - Family Account Tab Overlay ─────────────────────────────────

    function renderFamilyBilling(data, familyUid, yearId) {
        const $sec = $('#olama-reg-family-billing-section');
        $sec.empty();

        const sum = data.summary || { total_invoiced: 0, total_paid: 0, balance: 0 };
        const totalInvoiced = parseFloat(sum.total_invoiced || 0).toFixed(2);
        const totalPaid = parseFloat(sum.total_paid || 0).toFixed(2);
        const balance = parseFloat(sum.balance || 0).toFixed(2);

        let summaryHtml = `
        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; margin-bottom:25px; margin-top:20px;">
            <div style="background:#FFFBF3; border:1.5px solid #E0C090; border-radius:10px; padding:16px; text-align:center; box-shadow: 0 2px 6px rgba(232,146,10,0.05);">
                <div style="font-size:12px; font-weight:700; color:#C4780A; margin-bottom:6px;">إجمالي الفواتير والمطالبات</div>
                <div style="font-size:24px; font-weight:800; color:#1a1a2e;">${totalInvoiced} د.أ</div>
            </div>
            <div style="background:#E8F5E9; border:1.5px solid #a5d6a7; border-radius:10px; padding:16px; text-align:center; box-shadow: 0 2px 6px rgba(46,125,50,0.05);">
                <div style="font-size:12px; font-weight:700; color:#2E7D32; margin-bottom:6px;">إجمالي السداد الفعلي</div>
                <div style="font-size:24px; font-weight:800; color:#2E7D32;">${totalPaid} د.أ</div>
            </div>
            <div style="background:#FFF3E0; border:1.5px solid #ffcc80; border-radius:10px; padding:16px; text-align:center; box-shadow: 0 2px 6px rgba(232,146,10,0.1);">
                <div style="font-size:12px; font-weight:700; color:#E8920A; margin-bottom:6px;">المتبقي ذمة مستحقة</div>
                <div style="font-size:24px; font-weight:800; color:#E8920A;">${balance} د.أ</div>
            </div>
        </div>`;

        $sec.append(summaryHtml);

        let invoicesHtml = `
        <div class="olama-reg-section" style="margin-bottom: 25px;">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-media-text"></span>
                الفواتير والمطالبات المصدرة
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th>رقم الفاتورة</th>
                            <th>تاريخ الإصدار</th>
                            <th>العام الدراسي</th>
                            <th>الإجمالي</th>
                            <th>المدفوع</th>
                            <th>المتبقي</th>
                            <th>الحالة</th>
                            <th>الخيارات</th>
                        </tr>
                    </thead>
                    <tbody>`;

        if (!data.invoices || data.invoices.length === 0) {
            invoicesHtml += `
                        <tr>
                            <td colspan="8" class="olama-reg-empty-state">لا يوجد فواتير مصدرة لهذه العائلة خلال العام الدراسي المحدد.</td>
                        </tr>`;
        } else {
            data.invoices.forEach(inv => {
                let statusClass = 'olama-reg-badge--inactive';
                let statusLabel = 'مسودة';
                switch (inv.status) {
                    case 'issued':
                        statusClass = 'olama-reg-badge--active';
                        statusLabel = 'صادرة';
                        break;
                    case 'partial':
                        statusClass = 'olama-reg-badge--active';
                        statusLabel = 'جزئية';
                        break;
                    case 'paid':
                        statusClass = 'olama-reg-badge--active';
                        statusLabel = 'مدفوعة';
                        break;
                    case 'overdue':
                        statusClass = 'olama-reg-badge--blacklist';
                        statusLabel = 'متأخرة';
                        break;
                    case 'cancelled':
                        statusClass = 'olama-reg-badge--inactive';
                        statusLabel = 'ملغاة';
                        break;
                }

                const adminUrl = R.ajaxurl.replace('admin-ajax.php', 'admin.php');
                invoicesHtml += `
                        <tr>
                            <td><strong>${inv.invoice_number}</strong></td>
                            <td>${inv.issue_date}</td>
                            <td>${inv.academic_year_name || '—'}</td>
                            <td style="font-weight:700;">${parseFloat(inv.total).toFixed(2)}</td>
                            <td style="color:#2e7d32; font-weight:700;">${parseFloat(inv.amount_paid).toFixed(2)}</td>
                            <td class="olama-reg-balance-cell">${parseFloat(inv.balance).toFixed(2)}</td>
                            <td><span class="olama-reg-badge ${statusClass}">${statusLabel}</span></td>
                            <td>
                                <button class="button button-small olama-reg-view-invoice-btn" data-id="${inv.id}">
                                    <span class="dashicons dashicons-visibility" style="font-size:16px;vertical-align:middle;margin-top:2px;"></span>
                                </button>
                                <a href="${adminUrl}?page=olama-registration-invoices&action=print&id=${inv.id}" target="_blank" class="button button-small">
                                    <span class="dashicons dashicons-printer" style="font-size:16px;vertical-align:middle;margin-top:2px;"></span>
                                </a>
                            </td>
                        </tr>`;
            });
        }

        invoicesHtml += `
                    </tbody>
                </table>
            </div>
        </div>`;

        $sec.append(invoicesHtml);

        let paymentsHtml = `
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-money-alt"></span>
                السندات والمدفوعات المستلمة
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th>رقم السند</th>
                            <th>تاريخ القبض</th>
                            <th>الفاتورة المربوطة</th>
                            <th>طريقة الدفع</th>
                            <th>رقم المرجع / الشيك</th>
                            <th>القيمة المقبوضة</th>
                            <th>المستلم</th>
                            <th>إيصال</th>
                        </tr>
                    </thead>
                    <tbody>`;

        if (!data.payments || data.payments.length === 0) {
            paymentsHtml += `
                        <tr>
                            <td colspan="8" class="olama-reg-empty-state">لا يوجد دفعات مسجلة لهذه العائلة.</td>
                        </tr>`;
        } else {
            const payMethods = {
                cash: 'نقدي',
                bank_transfer: 'تحويل بنكي',
                cheque: 'شيك',
                online: 'دفع إلكتروني'
            };

            const adminUrl = R.ajaxurl.replace('admin-ajax.php', 'admin.php');
            data.payments.forEach(pay => {
                paymentsHtml += `
                        <tr>
                            <td>#${pay.id}</td>
                            <td>${pay.payment_date}</td>
                            <td><strong>${pay.invoice_number || '—'}</strong></td>
                            <td>${payMethods[pay.method] || pay.method}</td>
                            <td>${pay.reference || '—'}</td>
                            <td style="font-weight:700; color:#2e7d32;">${parseFloat(pay.amount).toFixed(2)}</td>
                            <td>${pay.received_by_name || '—'}</td>
                            <td>
                                <a href="${adminUrl}?page=olama-registration-payments&action=print_receipt&id=${pay.id}" target="_blank" class="button button-small">
                                    <span class="dashicons dashicons-printer" style="font-size:16px;vertical-align:middle;margin-top:2px;"></span>
                                </a>
                            </td>
                        </tr>`;
            });
        }

        paymentsHtml += `
                    </tbody>
                </table>
            </div>
        </div>`;

        $sec.append(paymentsHtml);
    }

    function loadFamilyBilling() {
        const familyUid = $('#olama-reg-family-uid').val();
        const yearId = $('#olama-reg-fin-year').val() || 0;
        if (!familyUid) return;

        if ($('#olama-reg-family-billing-section').length === 0) {
            $('#tab-financial').append('<div id="olama-reg-family-billing-section" style="margin-top: 30px;"></div>');
        }

        const $sec = $('#olama-reg-family-billing-section');
        $sec.html('<div class="olama-reg-empty-state"><span class="dashicons dashicons-update spin"></span> جاري تحميل الحساب المالي...</div>');

        ajax('olama_reg_get_family_billing', { family_uid: familyUid, academic_year_id: yearId })
            .done(res => {
                if (res.success) {
                    renderFamilyBilling(res.data, familyUid, yearId);
                } else {
                    $sec.html(`<div class="olama-reg-notice olama-reg-notice--error" style="display:block;">${res.data?.message || 'خطأ في تحميل البيانات الماليّة.'}</div>`);
                }
            })
            .fail(() => {
                $sec.html('<div class="olama-reg-notice olama-reg-notice--error" style="display:block;">خطأ في الاتصال بالخادم.</div>');
            });
    }

    // Reload family billing in sync when the year changes
    $(document).on('change', '#olama-reg-fin-year', function () {
        const familyUid = $(this).data('family-uid');
        const yearId = $(this).val();
        ajax('olama_reg_get_financial', { family_uid: familyUid, academic_year_id: yearId })
            .done(res => {
                if (res.success) {
                    renderFinTable(res.data.rows, res.data.totals, familyUid, yearId);
                    loadFamilyBilling();
                }
            });
    });

    // ── Init Helpers ──────────────────────────────────────────────────────────

    function initDatepickers($ctx) {
        if (typeof $.fn.datepicker === 'undefined') return;
        $ctx.find('.olama-reg-datepicker').each(function () {
            if (!$(this).hasClass('hasDatepicker')) {
                $(this).datepicker({ dateFormat: 'yy-mm-dd', changeYear: true, changeMonth: true, yearRange: '1970:2050' });
            }
        });
    }

    function initSelect2($ctx) {
        if (typeof $.fn.select2 === 'undefined') return;
        $ctx.find('.olama-reg-select2').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ dir: 'rtl', width: '100%' });
            }
        });
    }

    // ── Page Init ─────────────────────────────────────────────────────────────

    $(document).ready(function () {
        if ($('#olama-reg-payment-modal').length === 0) {
            $('body').append(paymentModalHtml);
        }

        initDatepickers($('.olama-reg-wrap'));
        initSelect2($('.olama-reg-wrap'));

        if ($('.olama-reg-tab.active[data-tab="financial"]').length) {
            loadFamilyBilling();
        }
    });

    // =========================================================================
    // Customers Page — Full CRUD Modal + Children Expand/Collapse
    // =========================================================================

    // ── Helpers ────────────────────────────────────────────────────────────────

    function custNotice(msg, isError) {
        const $n = $('#cust_notice');
        if (!$n.length) { showNotice(msg, isError); return; }
        $n.text(msg)
            .css({
                background: isError ? '#fee2e2' : '#dcfce7',
                color: isError ? '#dc2626' : '#16a34a',
                border: '1px solid ' + (isError ? '#fca5a5' : '#86efac'),
            })
            .show();
        clearTimeout(window._custNoticeTimer);
        window._custNoticeTimer = setTimeout(() => $n.fadeOut(), 5000);
    }

    // Build a saved-child row HTML (for edit modal)
    function buildSavedChildRow(child) {
        return `
        <div class="cust-saved-child-row" data-child-id="${child.id}" style="display:flex; align-items:center; gap:8px; padding:8px 10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
            <div style="flex:1;">
                <span class="cust-child-name" style="font-weight:700; font-size:13px;">${escHtml(child.child_name)}</span>
                <span class="cust-child-grade" style="font-size:11px; color:#64748b; margin-right:8px;">${escHtml(child.grade || '')}</span>
            </div>
            <button type="button" class="button button-small cust-child-edit-btn" data-child-id="${child.id}" title="تعديل">
                <span class="dashicons dashicons-edit" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
            </button>
            <button type="button" class="button button-small cust-child-delete-btn" data-child-id="${child.id}" title="حذف" style="color:#dc2626; border-color:#fca5a5;">
                <span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
            </button>
        </div>`;
    }

    // Build a pending child row HTML (for create modal)
    function buildPendingChildRow(name, grade, idx) {
        return `
        <div class="cust-pending-child-row" data-idx="${idx}" style="display:flex; align-items:center; gap:8px; padding:8px 10px; background:#f0fdf4; border:1px dashed #86efac; border-radius:6px;">
            <div style="flex:1;">
                <span style="font-weight:700; font-size:13px;">${escHtml(name)}</span>
                ${grade ? `<span style="font-size:11px; color:#64748b; margin-right:8px;">${escHtml(grade)}</span>` : ''}
            </div>
            <button type="button" class="button-link cust-pending-child-remove" style="color:#dc2626; font-size:18px; line-height:1; padding:0;">×</button>
        </div>`;
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    let _custPendingChildren = []; // for create mode
    let _custEditId = 0;           // 0 = create mode

    // ── Open Modal (Create) ────────────────────────────────────────────────────

    $(document).on('click', '#cust_btn_add_new', function () {
        _custEditId = 0;
        _custPendingChildren = [];
        $('#cust-modal-customer-id').val('');
        $('#cust-modal-name').val('');
        $('#cust-modal-phone').val('');
        $('#cust-modal-notes').val('');
        $('#cust-modal-title').html('<span class="dashicons dashicons-businessman" style="font-size:20px;width:20px;height:20px;"></span> إضافة عميل جديد');
        $('#cust-modal-save-label').text('حفظ');
        $('#cust-modal-saved-children').empty();
        $('#cust-modal-pending-children').empty();
        $('#cust-modal-new-child-form').hide();
        $('#cust-modal-no-children').show();
        $('#cust-modal-overlay').css('display', 'flex');
        setTimeout(() => $('#cust-modal-name').focus(), 100);
    });

    // ── Open Modal (Edit) ──────────────────────────────────────────────────────

    $(document).on('click', '.cust-btn-edit', function () {
        const id = parseInt($(this).data('id'));
        _custEditId = id;
        _custPendingChildren = [];

        $('#cust-modal-title').html('<span class="dashicons dashicons-edit" style="font-size:20px;width:20px;height:20px;"></span> تعديل بيانات العميل');
        $('#cust-modal-save-label').text('حفظ التعديلات');
        $('#cust-modal-customer-id').val(id);
        $('#cust-modal-saved-children').html('<div style="color:#94a3b8; font-size:13px; padding:8px;"><span class="spinner is-active" style="float:none;"></span> جاري التحميل...</div>');
        $('#cust-modal-pending-children').empty();
        $('#cust-modal-new-child-form').hide();
        $('#cust-modal-no-children').hide();
        $('#cust-modal-overlay').css('display', 'flex');

        ajax('olama_reg_get_external_customer', { customer_id: id })
            .done(res => {
                if (res.success) {
                    const c = res.data.customer;
                    $('#cust-modal-name').val(c.customer_name);
                    $('#cust-modal-phone').val(c.phone || '');
                    $('#cust-modal-notes').val(c.notes || '');

                    const children = res.data.children || [];
                    $('#cust-modal-saved-children').empty();
                    if (children.length) {
                        children.forEach(ch => $('#cust-modal-saved-children').append(buildSavedChildRow(ch)));
                        $('#cust-modal-no-children').hide();
                    } else {
                        $('#cust-modal-no-children').show();
                    }
                }
            });
    });

    // ── Close Modal ────────────────────────────────────────────────────────────

    $(document).on('click', '#cust-modal-close, #cust-modal-cancel', function () {
        $('#cust-modal-overlay').hide();
    });
    $(document).on('click', '#cust-modal-overlay', function (e) {
        if (e.target === this) $('#cust-modal-overlay').hide();
    });

    // ── Show new child form ────────────────────────────────────────────────────

    $(document).on('click', '#cust-modal-add-child-btn', function () {
        $('#cust-modal-new-child-name').val('');
        $('#cust-modal-new-child-grade').val('');
        $('#cust-modal-new-child-form').show();
        setTimeout(() => $('#cust-modal-new-child-name').focus(), 50);
    });

    $(document).on('click', '#cust-modal-cancel-new-child', function () {
        $('#cust-modal-new-child-form').hide();
    });

    // ── Save new child (AJAX in edit mode, pending in create mode) ─────────────

    $(document).on('click', '#cust-modal-save-new-child', function () {
        const name = $('#cust-modal-new-child-name').val().trim();
        const grade = $('#cust-modal-new-child-grade').val().trim();
        if (!name) { $('#cust-modal-new-child-name').focus(); return; }

        if (_custEditId) {
            // Edit mode → save to DB immediately
            $('#cust-modal-new-child-loading').show();
            ajax('olama_reg_add_child_to_customer', { customer_id: _custEditId, child_name: name, grade })
                .done(res => {
                    $('#cust-modal-new-child-loading').hide();
                    if (res.success && res.data.child) {
                        $('#cust-modal-saved-children').append(buildSavedChildRow(res.data.child));
                        $('#cust-modal-no-children').hide();
                        // Update count in table row
                        const $cnt = $(`.cust-children-count[data-id="${_custEditId}"]`);
                        $cnt.text(parseInt($cnt.text() || 0) + 1);
                        $('#cust-modal-new-child-form').hide();
                    } else {
                        alert(res.data?.message || 'خطأ في إضافة الابن');
                    }
                })
                .fail(() => { $('#cust-modal-new-child-loading').hide(); alert(R.strings.error); });
        } else {
            // Create mode → queue as pending
            const idx = _custPendingChildren.length;
            _custPendingChildren.push({ name, grade });
            $('#cust-modal-pending-children').append(buildPendingChildRow(name, grade, idx));
            $('#cust-modal-no-children').hide();
            $('#cust-modal-new-child-form').hide();
        }
    });

    // ── Remove pending child ───────────────────────────────────────────────────

    $(document).on('click', '.cust-pending-child-remove', function () {
        const idx = parseInt($(this).closest('.cust-pending-child-row').data('idx'));
        _custPendingChildren.splice(idx, 1);
        $(this).closest('.cust-pending-child-row').remove();
        // Re-index
        _custPendingChildren.forEach((c, i) => {
            $('#cust-modal-pending-children .cust-pending-child-row').eq(i).attr('data-idx', i);
        });
        if (!$('#cust-modal-saved-children .cust-saved-child-row').length && !_custPendingChildren.length) {
            $('#cust-modal-no-children').show();
        }
    });

    // ── Edit saved child inline ────────────────────────────────────────────────

    $(document).on('click', '.cust-child-edit-btn', function () {
        const $row = $(this).closest('.cust-saved-child-row');
        const childId = parseInt($row.data('child-id'));
        const curName = $row.find('.cust-child-name').text().trim();
        const curGrade = $row.find('.cust-child-grade').text().trim();

        // Replace row with inline edit form
        $row.html(`
            <div style="display:flex; gap:8px; align-items:flex-end; flex:1;">
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:700; display:block; margin-bottom:2px;">الاسم</label>
                    <input type="text" class="cust-child-edit-name regular-text" value="${escHtml(curName)}" style="width:100%;">
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:700; display:block; margin-bottom:2px;">الصف</label>
                    <input type="text" class="cust-child-edit-grade regular-text" value="${escHtml(curGrade)}" style="width:100%;">
                </div>
                <button type="button" class="button button-small cust-child-edit-save" data-child-id="${childId}" style="height:32px;">حفظ</button>
                <button type="button" class="button button-small cust-child-edit-cancel" data-child-id="${childId}" data-name="${escHtml(curName)}" data-grade="${escHtml(curGrade)}" style="height:32px;">إلغاء</button>
            </div>`);
    });

    $(document).on('click', '.cust-child-edit-cancel', function () {
        const $row = $(this).closest('.cust-saved-child-row');
        const childId = parseInt($(this).data('child-id'));
        const name = $(this).data('name');
        const grade = $(this).data('grade');
        $row.html(buildSavedChildRow({ id: childId, child_name: name, grade }).match(/<div class="cust-saved-child-row"[^>]*>([\/\s\S]*)<\/div>/)?.[1] || '');
        // Simpler: just rebuild the row entirely
        $row.replaceWith(buildSavedChildRow({ id: childId, child_name: name, grade }));
    });

    $(document).on('click', '.cust-child-edit-save', function () {
        const $row = $(this).closest('.cust-saved-child-row');
        const childId = parseInt($(this).data('child-id'));
        const name = $row.find('.cust-child-edit-name').val().trim();
        const grade = $row.find('.cust-child-edit-grade').val().trim();
        if (!name) { $row.find('.cust-child-edit-name').focus(); return; }

        ajax('olama_reg_update_child', { child_id: childId, child_name: name, grade })
            .done(res => {
                if (res.success && res.data.child) {
                    $row.replaceWith(buildSavedChildRow(res.data.child));
                } else {
                    alert(res.data?.message || R.strings.error);
                }
            });
    });

    // ── Delete saved child ─────────────────────────────────────────────────────

    $(document).on('click', '.cust-child-delete-btn', function () {
        if (!confirm('هل تريد حذف هذا الابن؟')) return;
        const $row = $(this).closest('.cust-saved-child-row');
        const childId = parseInt($row.data('child-id'));

        ajax('olama_reg_delete_child', { child_id: childId })
            .done(res => {
                if (res.success) {
                    $row.fadeOut(200, function () {
                        $(this).remove();
                        // Update count
                        if (_custEditId) {
                            const $cnt = $(`.cust-children-count[data-id="${_custEditId}"]`);
                            $cnt.text(Math.max(0, parseInt($cnt.text() || 0) - 1));
                        }
                        if (!$('#cust-modal-saved-children .cust-saved-child-row').length && !_custPendingChildren.length) {
                            $('#cust-modal-no-children').show();
                        }
                    });
                } else {
                    alert(res.data?.message || R.strings.error);
                }
            });
    });

    // ── Save Customer (Create or Update) ──────────────────────────────────────

    $(document).on('click', '#cust-modal-save', function () {
        const $btn = $(this);
        const name = $('#cust-modal-name').val().trim();
        const phone = $('#cust-modal-phone').val().trim();
        const notes = $('#cust-modal-notes').val().trim();

        if (!name) { $('#cust-modal-name').focus(); custNotice('اسم العميل مطلوب.', true); return; }

        setLoading($btn, true);

        if (_custEditId) {
            // Update
            ajax('olama_reg_update_external_customer', { customer_id: _custEditId, customer_name: name, phone, notes })
                .done(res => {
                    setLoading($btn, false);
                    if (res.success) {
                        custNotice('تم تحديث بيانات العميل بنجاح.');
                        // Update row in table
                        const $row = $(`.cust-row[data-id="${_custEditId}"]`);
                        $row.find('strong').first().text(name);
                        $row.find('td').eq(3).text(phone || '—');
                        $('#cust-modal-overlay').hide();
                    } else {
                        custNotice(res.data?.message || R.strings.error, true);
                    }
                })
                .fail(() => { setLoading($btn, false); custNotice(R.strings.error, true); });
        } else {
            // Create
            ajax('olama_reg_add_external_customer', {
                customer_name: name,
                phone,
                notes,
                children: JSON.stringify(_custPendingChildren)
            })
                .done(res => {
                    setLoading($btn, false);
                    if (res.success) {
                        custNotice('تم إضافة العميل بنجاح.');
                        $('#cust-modal-overlay').hide();
                        // Reload page to show new customer contextually if on dashboard
                        if ($('#os-hub-data').length) {
                            setTimeout(() => {
                                window.location.href = window.location.pathname + '?page=olama-registration&customer_uid=' + encodeURIComponent(res.data.customer_uid || '');
                            }, 800);
                        } else {
                            setTimeout(() => window.location.reload(), 800);
                        }
                    } else {
                        custNotice(res.data?.message || R.strings.error, true);
                    }
                })
                .fail(() => { setLoading($btn, false); custNotice(R.strings.error, true); });
        }
    });

    // ── Delete Customer ────────────────────────────────────────────────────────

    $(document).on('click', '.cust-btn-delete', function () {
        const id = parseInt($(this).data('id'));
        const name = $(this).data('name');
        if (!confirm(`هل تريد حذف العميل "${name}"؟ سيتم إخفاء جميع أبنائه أيضاً.`)) return;

        ajax('olama_reg_delete_external_customer', { customer_id: id })
            .done(res => {
                if (res.success) {
                    // Remove customer row + children row
                    $(`.cust-row[data-id="${id}"]`).fadeOut(300, function () { $(this).remove(); });
                    $(`.cust-children-row[data-parent-id="${id}"]`).remove();
                    custNotice('تم حذف العميل بنجاح.');
                } else {
                    custNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    // ── Expand/Collapse Children Row ──────────────────────────────────────────

    $(document).on('click', '.cust-toggle-children', function () {
        const id = parseInt($(this).data('id'));
        const $icon = $(this).find('.cust-toggle-icon');
        const $childRow = $(`.cust-children-row[data-parent-id="${id}"]`);

        if ($childRow.is(':visible')) {
            $childRow.hide();
            $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            return;
        }

        $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
        $childRow.show();

        const $container = $childRow.find('.cust-children-container');
        // Only load once
        if ($container.data('loaded')) return;
        $container.data('loaded', true);

        $container.html('<div style="color:#94a3b8; padding:8px;"><span class="spinner is-active" style="float:none;"></span> جاري التحميل...</div>');

        ajax('olama_reg_get_external_customer', { customer_id: id })
            .done(res => {
                if (!res.success) { $container.html('<div style="color:#dc2626;">خطأ في تحميل البيانات.</div>'); return; }

                const children = res.data.children || [];
                renderChildrenInline($container, id, children);
            });
    });

    function renderChildrenInline($container, customerId, children) {
        let html = `<div class="cust-inline-children" data-customer-id="${customerId}">`;

        if (children.length) {
            html += `<table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:10px;">
                <thead><tr style="background:#e0f2fe;">
                    <th style="padding:6px 10px; text-align:right;">اسم الابن</th>
                    <th style="padding:6px 10px; text-align:right;">الصف</th>
                    <th style="padding:6px 10px; width:90px; text-align:center;">إجراءات</th>
                </tr></thead><tbody>`;

            children.forEach(ch => {
                html += `<tr class="cust-inline-child-tr" data-child-id="${ch.id}">
                    <td style="padding:6px 10px; font-weight:600;">${escHtml(ch.child_name)}</td>
                    <td style="padding:6px 10px; color:#64748b;">${escHtml(ch.grade || '—')}</td>
                    <td style="padding:6px 4px; text-align:center; white-space:nowrap;">
                        <button class="button button-small cust-inline-edit-child" data-child-id="${ch.id}" title="تعديل" style="margin-left:3px;">
                            <span class="dashicons dashicons-edit" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
                        </button>
                        <button class="button button-small cust-inline-delete-child" data-child-id="${ch.id}" data-customer-id="${customerId}" title="حذف" style="color:#dc2626; border-color:#fca5a5;">
                            <span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
                        </button>
                    </td>
                </tr>`;
            });

            html += '</tbody></table>';
        } else {
            html += `<div style="color:#94a3b8; font-size:13px; padding:6px 0 10px; font-style:italic;">لا يوجد أبناء مسجلون.</div>`;
        }

        html += `<div class="cust-inline-add-form" style="display:none; background:#f0fdf4; border:1px dashed #86efac; border-radius:8px; padding:10px; margin-bottom:8px;">
            <div style="display:flex; gap:8px; align-items:flex-end;">
                <div style="flex:2;">
                    <label style="font-size:11px; font-weight:700; display:block; margin-bottom:3px;">اسم الابن *</label>
                    <input type="text" class="cust-inline-new-name regular-text" style="width:100%;" placeholder="الاسم الكامل">
                </div>
                <div style="flex:1;">
                    <label style="font-size:11px; font-weight:700; display:block; margin-bottom:3px;">الصف</label>
                    <input type="text" class="cust-inline-new-grade regular-text" style="width:100%;" placeholder="مثال: رابع">
                </div>
                <button type="button" class="button button-primary cust-inline-save-child" data-customer-id="${customerId}" style="height:34px; background:var(--reg-success); border-color:var(--reg-success); white-space:nowrap;">حفظ</button>
                <button type="button" class="button button-secondary cust-inline-cancel-add" style="height:34px;">إلغاء</button>
            </div>
        </div>
        <button type="button" class="button button-secondary cust-inline-add-btn" data-customer-id="${customerId}" style="font-size:12px; height:28px; display:flex; align-items:center; gap:4px;">
            <span class="dashicons dashicons-plus" style="font-size:14px;width:14px;height:14px;margin-top:2px;"></span>
            إضافة ابن
        </button></div>`;

        $container.html(html);
    }

    // Show inline add form
    $(document).on('click', '.cust-inline-add-btn', function () {
        const $wrap = $(this).closest('.cust-inline-children');
        $wrap.find('.cust-inline-add-form').show();
        $wrap.find('.cust-inline-new-name').focus();
        $(this).hide();
    });
    $(document).on('click', '.cust-inline-cancel-add', function () {
        const $wrap = $(this).closest('.cust-inline-children');
        $wrap.find('.cust-inline-add-form').hide().find('input').val('');
        $wrap.find('.cust-inline-add-btn').show();
    });

    // Save inline new child
    $(document).on('click', '.cust-inline-save-child', function () {
        const $wrap = $(this).closest('.cust-inline-children');
        const customerId = parseInt($(this).data('customer-id'));
        const name = $wrap.find('.cust-inline-new-name').val().trim();
        const grade = $wrap.find('.cust-inline-new-grade').val().trim();
        if (!name) { $wrap.find('.cust-inline-new-name').focus(); return; }

        ajax('olama_reg_add_child_to_customer', { customer_id: customerId, child_name: name, grade })
            .done(res => {
                if (res.success && res.data.child) {
                    const ch = res.data.child;
                    // Add row to table
                    let $tbody = $wrap.find('table tbody');
                    if (!$tbody.length) {
                        // No table yet — re-render
                        ajax('olama_reg_get_external_customer', { customer_id: customerId })
                            .done(r2 => {
                                if (r2.success) renderChildrenInline($wrap.closest('.cust-children-container'), customerId, r2.data.children || []);
                            });
                        return;
                    }
                    $tbody.append(`<tr class="cust-inline-child-tr" data-child-id="${ch.id}">
                        <td style="padding:6px 10px; font-weight:600;">${escHtml(ch.child_name)}</td>
                        <td style="padding:6px 10px; color:#64748b;">${escHtml(ch.grade || '—')}</td>
                        <td style="padding:6px 4px; text-align:center; white-space:nowrap;">
                            <button class="button button-small cust-inline-edit-child" data-child-id="${ch.id}" title="تعديل" style="margin-left:3px;"><span class="dashicons dashicons-edit" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span></button>
                            <button class="button button-small cust-inline-delete-child" data-child-id="${ch.id}" data-customer-id="${customerId}" title="حذف" style="color:#dc2626; border-color:#fca5a5;"><span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span></button>
                        </td></tr>`);
                    $wrap.find('.cust-inline-add-form').hide().find('input').val('');
                    $wrap.find('.cust-inline-add-btn').show();
                    // Remove empty state text
                    $wrap.find('> div[style*="font-style:italic"]').remove();
                    // Update count badge
                    const $cnt = $(`.cust-children-count[data-id="${customerId}"]`);
                    $cnt.text(parseInt($cnt.text() || 0) + 1);
                } else {
                    alert(res.data?.message || R.strings.error);
                }
            });
    });

    // Edit inline child
    $(document).on('click', '.cust-inline-edit-child', function () {
        const $tr = $(this).closest('.cust-inline-child-tr');
        const childId = parseInt($tr.data('child-id'));
        const name = $tr.find('td').eq(0).text().trim();
        const grade = $tr.find('td').eq(1).text().trim().replace('—', '');

        $tr.html(`
            <td colspan="2" style="padding:6px 4px;">
                <div style="display:flex; gap:8px;">
                    <input type="text" class="cust-inline-edit-name regular-text" value="${escHtml(name)}" style="flex:2;">
                    <input type="text" class="cust-inline-edit-grade regular-text" value="${escHtml(grade)}" style="flex:1;" placeholder="الصف">
                </div>
            </td>
            <td style="padding:6px 4px; white-space:nowrap;">
                <button class="button button-small cust-inline-update-child" data-child-id="${childId}" style="height:30px;">حفظ</button>
                <button class="button button-small cust-inline-cancel-edit" data-child-id="${childId}" data-name="${escHtml(name)}" data-grade="${escHtml(grade)}" style="height:30px;">إلغاء</button>
            </td>`);
    });

    $(document).on('click', '.cust-inline-cancel-edit', function () {
        const $tr = $(this).closest('.cust-inline-child-tr');
        const childId = parseInt($(this).data('child-id'));
        const customerId = parseInt($tr.closest('.cust-inline-children').data('customer-id'));
        const name = $(this).data('name');
        const grade = $(this).data('grade');
        $tr.html(`
            <td style="padding:6px 10px; font-weight:600;">${escHtml(name)}</td>
            <td style="padding:6px 10px; color:#64748b;">${escHtml(grade || '—')}</td>
            <td style="padding:6px 4px; text-align:center; white-space:nowrap;">
                <button class="button button-small cust-inline-edit-child" data-child-id="${childId}" title="تعديل" style="margin-left:3px;"><span class="dashicons dashicons-edit" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span></button>
                <button class="button button-small cust-inline-delete-child" data-child-id="${childId}" data-customer-id="${customerId}" title="حذف" style="color:#dc2626; border-color:#fca5a5;"><span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span></button>
            </td>`);
    });

    $(document).on('click', '.cust-inline-update-child', function () {
        const $tr = $(this).closest('.cust-inline-child-tr');
        const childId = parseInt($(this).data('child-id'));
        const customerId = parseInt($tr.closest('.cust-inline-children').data('customer-id'));
        const name = $tr.find('.cust-inline-edit-name').val().trim();
        const grade = $tr.find('.cust-inline-edit-grade').val().trim();
        if (!name) { $tr.find('.cust-inline-edit-name').focus(); return; }

        ajax('olama_reg_update_child', { child_id: childId, child_name: name, grade })
            .done(res => {
                if (res.success && res.data.child) {
                    const ch = res.data.child;
                    $tr.html(`
                        <td style="padding:6px 10px; font-weight:600;">${escHtml(ch.child_name)}</td>
                        <td style="padding:6px 10px; color:#64748b;">${escHtml(ch.grade || '—')}</td>
                        <td style="padding:6px 4px; text-align:center; white-space:nowrap;">
                            <button class="button button-small cust-inline-edit-child" data-child-id="${ch.id}" title="تعديل" style="margin-left:3px;"><span class="dashicons dashicons-edit" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span></button>
                            <button class="button button-small cust-inline-delete-child" data-child-id="${ch.id}" data-customer-id="${customerId}" title="حذف" style="color:#dc2626; border-color:#fca5a5;"><span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span></button>
                        </td>`);
                } else {
                    alert(res.data?.message || R.strings.error);
                }
            });
    });

    // Delete inline child
    $(document).on('click', '.cust-inline-delete-child', function () {
        if (!confirm('هل تريد حذف هذا الابن؟')) return;
        const $tr = $(this).closest('.cust-inline-child-tr');
        const childId = parseInt($(this).data('child-id'));
        const customerId = parseInt($(this).data('customer-id'));

        ajax('olama_reg_delete_child', { child_id: childId })
            .done(res => {
                if (res.success) {
                    $tr.fadeOut(200, function () {
                        $(this).remove();
                        const $cnt = $(`.cust-children-count[data-id="${customerId}"]`);
                        $cnt.text(Math.max(0, parseInt($cnt.text() || 0) - 1));
                    });
                } else {
                    alert(res.data?.message || R.strings.error);
                }
            });
    });

    // ── Search debounce ────────────────────────────────────────────────────────

    let _custSearchTimer;
    $(document).on('input', '#cust_search_input', function () {
        clearTimeout(_custSearchTimer);
        const q = $(this).val();
        _custSearchTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            if (q) url.searchParams.set('s', q);
            else url.searchParams.delete('s');
            url.searchParams.delete('paged');
            window.location.href = url.toString();
        }, 600);
    });

    // =========================================================================
    // Quick-Add External Customer Modal (custom-payments page)
    // =========================================================================

    $(document).on('click', '#cp_btn_add_ext_customer', function () {
        $('#modal_ext_children_list').empty();
        $('#modal_ext_name, #modal_ext_phone, #modal_ext_notes').val('');
        $('#ext-quick-modal-overlay').css('display', 'flex');
        setTimeout(() => $('#modal_ext_name').focus(), 50);
    });

    $(document).on('click', '#modal_ext_btn_cancel, #modal_ext_btn_cancel2', function () {
        $('#ext-quick-modal-overlay').hide();
    });

    $(document).on('click', '#modal_ext_add_child_btn', function () {
        $('#modal_ext_children_list').append(`
            <div style="display:flex; gap:5px;" class="modal-child-row">
                <input type="text" class="modal-child-name regular-text" placeholder="اسم الابن/الابنة" style="flex:2">
                <input type="text" class="modal-child-grade regular-text" placeholder="الصف (اختياري)" style="flex:1">
                <button type="button" class="button btn-remove-modal-child" style="min-width:32px;">×</button>
            </div>
        `);
    });

    $(document).on('click', '.btn-remove-modal-child', function () {
        $(this).closest('.modal-child-row').remove();
    });

    $(document).on('click', '#modal_ext_btn_save', function () {
        const name = $('#modal_ext_name').val().trim();
        const phone = $('#modal_ext_phone').val().trim();
        const notes = $('#modal_ext_notes').val().trim();
        const $btn = $(this);

        if (!name) { alert('الرجاء إدخال اسم العميل'); return; }

        const children = [];
        $('#modal_ext_children_list .modal-child-row').each(function () {
            const cName = $(this).find('.modal-child-name').val().trim();
            const cGrade = $(this).find('.modal-child-grade').val().trim();
            if (cName) children.push({ name: cName, grade: cGrade });
        });

        setLoading($btn, true);

        ajax('olama_reg_add_external_customer', {
            customer_name: name,
            phone,
            notes,
            children: JSON.stringify(children)
        }).done(res => {
            setLoading($btn, false);
            if (res.success && res.data.customer_id) {
                if ($('#cp_ext_customer_search').length) {
                    const uid = res.data.customer_uid ? `[${res.data.customer_uid}] ` : '';
                    const newOption = new Option(`${uid}${name}${phone ? ' - ' + phone : ''}`, res.data.customer_id, true, true);
                    $('#cp_ext_customer_search').append(newOption).trigger('change');
                    $('#ext-quick-modal-overlay').hide();
                } else {
                    window.location.reload();
                }
            } else {
                alert(res.data?.message || 'حدث خطأ أثناء حفظ العميل');
            }
        }).fail(() => { setLoading($btn, false); alert(R.strings.error); });
    });

    // =========================================================================
    // Custom Payments — shared helpers (available in both standalone page & Hub modal)
    // =========================================================================

    // Build a DB-backed child checkbox (data-child-id)
    // Defined outside the if-guard so it's accessible from os-hub.js context too
    function cpAddChildCheckbox(childId, name, grade, checked) {
        const id = `cp_ext_ch_${childId}`;
        const html = `
            <label for="${id}" style="display:flex; align-items:center; gap:8px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">
                <input type="checkbox" id="${id}" class="cp-ext-student-check" name="child_ids[]" value="${childId}" ${checked ? 'checked' : ''} style="width:16px;height:16px;">
                <span>
                    <span style="display:block; font-weight:700;">${escHtml(name)}</span>
                    <span style="display:block; font-size:11px; color:#64748b;">${escHtml(grade || 'بدون صف')}</span>
                </span>
            </label>`;
        $('#cp_ext_students_list').append(html);
        $('#cp_ext_no_children_msg').hide();
    }

    // cpCalcTotal — reads customer_type from checked radio OR from hidden input fallback (Hub context)
    function cpCalcTotal() {
        const radioVal = $('input[name="customer_type"]:checked').val();
        // Hidden input is added by os-hub.js when opening modal on Hub page (disabled radios don't serialize)
        const hiddenVal = $('input[name="customer_type"][type="hidden"]').val();
        const isExternal = (radioVal || hiddenVal) === 'external';
        const isDirect = $('#cp_ext_pay_customer_direct').is(':checked');
        let count;
        if (isExternal && isDirect) {
            count = 1;
        } else if (isExternal) {
            count = $('.cp-ext-student-check:checked').length;
        } else {
            count = $('.cp-student-check:checked').length;
        }
        const amount = parseFloat($('#cp_amount').val()) || 0;
        const discount = parseFloat($('#cp_discount').val()) || 0;

        let total = Math.max(0, (count * amount) - (count * discount));

        $('#cp_total_display').text(total.toFixed(2) + ' د.أ');
        if (count > 0) $('#cp_students_error').hide();
    }

    // =========================================================================
    // Custom Payments — DB-backed child checkboxes
    // =========================================================================
    if ($('#cp_family_search').length || $('#cp_ext_customer_search').length) {

        // Init Family Select2
        if ($('#cp_family_search').length && typeof $.fn.select2 !== 'undefined') {
            $('#cp_family_search').select2({
                dir: 'rtl', width: '100%',
                ajax: {
                    url: R.ajaxurl, type: 'POST', dataType: 'json', delay: 250,
                    data: params => ({ action: 'olama_reg_search', nonce: R.nonce, q: params.term }),
                    processResults: data => ({
                        results: (data.success && data.data.families) ? data.data.families.map(f => {
                            const fullName = ((f.father_first_name || '') + ' ' + (f.father_family_name || '')).trim() || 'بدون اسم';
                            return { id: f.family_uid, text: `${fullName} (${f.family_uid})` };
                        }) : []
                    })
                },
                placeholder: '-- ابحث باسم الأب، الأم، أو رقم العائلة --',
                minimumInputLength: 3
            });
        }

        // Init External Customer Select2
        if ($('#cp_ext_customer_search').length && typeof $.fn.select2 !== 'undefined') {
            $('#cp_ext_customer_search').select2({
                dir: 'rtl', width: '100%',
                ajax: {
                    url: R.ajaxurl, type: 'POST', dataType: 'json', delay: 250,
                    data: params => ({ action: 'olama_reg_search_external_customers', nonce: R.nonce, q: params.term }),
                    processResults: data => ({
                        results: (data.success && data.data.customers) ? data.data.customers.map(c => {
                            const uid = c.customer_uid ? `[${c.customer_uid}] ` : '';
                            return { id: c.id, text: `${uid}${c.customer_name}${c.phone ? ' - ' + c.phone : ''}` };
                        }) : []
                    })
                },
                placeholder: '-- ابحث بالاسم أو الهاتف أو اسم الابن --',
                minimumInputLength: 2
            });
        }

        // External customer change → load children from DB
        // Delegated event so it works when select is re-populated in Hub modal
        $(document).on('change', '#cp_ext_customer_search', function () {
            const customerId = parseInt($(this).val()) || 0;
            $('#cp_ext_customer_id').val(customerId);

            if (!customerId) {
                $('#cp_ext_children_section').hide();
                $('#cp_ext_no_customer_msg').show();
                $('#cp_ext_students_list').empty();
                cpCalcTotal();
                return;
            }

            // Load children from DB
            $('#cp_ext_no_customer_msg').hide();
            $('#cp_ext_children_section').show();
            $('#cp_ext_students_list').html('<div style="grid-column:1/-1; text-align:center; color:var(--reg-primary);"><span class="spinner is-active" style="float:none;"></span> جاري التحميل...</div>');
            $('#cp_ext_no_children_msg').hide();

            ajax('olama_reg_get_external_customer_children', { customer_id: customerId })
                .done(res => {
                    $('#cp_ext_students_list').empty();
                    const children = res.success ? (res.data.children || []) : [];
                    if (children.length) {
                        children.forEach(ch => cpAddChildCheckbox(ch.id, ch.child_name, ch.grade, true));
                        $('#cp_ext_no_children_msg').hide();
                    } else {
                        $('#cp_ext_no_children_msg').show();
                    }
                    cpCalcTotal();
                });
        });

        // Toggle UI
        $('input[name="customer_type"]').on('change', function () {
            if ($(this).val() === 'external') {
                $('#cp_internal_customer_wrap').hide();
                $('#cp_external_customer_wrap').show();
                $('#cp_students_container').hide();
                $('.cp-student-check').prop('checked', false);
            } else {
                $('#cp_internal_customer_wrap').show();
                $('#cp_external_customer_wrap').hide();
                if ($('#cp_family_uid').val()) $('#cp_students_container').show();
            }
            cpCalcTotal();
        });

        // Family change → load students
        // Delegated event so it works when the select is pre-filled in Hub modal context
        $(document).on('change', '#cp_family_search', function () {
            const familyUid = $(this).val();
            $('#cp_family_uid').val(familyUid);
            const $list = $('#cp_students_list');
            $list.empty();
            if (!familyUid) { $('#cp_students_container').hide(); cpCalcTotal(); return; }

            $('#cp_students_container').show();
            $list.html('<div style="grid-column:1/-1; text-align:center; color:var(--reg-primary);"><span class="spinner is-active" style="float:none;"></span> جاري تحميل الطلاب...</div>');

            ajax('olama_reg_get_family_students', { family_uid: familyUid })
                .done(res => {
                    $list.empty();
                    if (res.success && res.data.students && res.data.students.length) {
                        res.data.students.forEach(st => {
                            $list.append(`
                                <label style="display:flex; align-items:center; gap:8px; padding:10px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">
                                    <input type="checkbox" name="student_uids[]" value="${st.student_uid}" class="cp-student-check" checked style="width:16px;height:16px;">
                                    <span>
                                        <span style="display:block; font-weight:700;">${escHtml(st.student_name || 'بدون اسم')}</span>
                                        <span style="display:block; font-size:11px; color:#64748b;">${escHtml(st.grade_name || '')}</span>
                                    </span>
                                </label>`);
                        });
                    } else {
                        $list.html('<div style="grid-column:1/-1; color:#dc2626;">لا يوجد طلاب نشطين لهذه العائلة.</div>');
                    }
                    cpCalcTotal();
                });
        });

        // Quick-add child during payment → saves to DB first
        $(document).on('click', '#cp_ext_quick_add_child_btn', function () {
            $('#cp_ext_new_child_row').show();
            $('#cp_new_child_name').focus();
        });
        $(document).on('click', '#cp_new_child_cancel_btn', function () {
            $('#cp_ext_new_child_row').hide();
            $('#cp_new_child_name, #cp_new_child_grade').val('');
        });
        $(document).on('click', '#cp_new_child_save_btn', function () {
            const customerId = parseInt($('#cp_ext_customer_id').val()) || 0;
            const name = $('#cp_new_child_name').val().trim();
            const grade = $('#cp_new_child_grade').val().trim();

            if (!customerId) { alert('يرجى تحديد عميل خارجي أولاً.'); return; }
            if (!name) { $('#cp_new_child_name').focus(); return; }

            $('#cp_new_child_loading').show();

            ajax('olama_reg_add_child_to_customer', { customer_id: customerId, child_name: name, grade })
                .done(res => {
                    $('#cp_new_child_loading').hide();
                    if (res.success && res.data.child) {
                        const ch = res.data.child;
                        cpAddChildCheckbox(ch.id, ch.child_name, ch.grade, true);
                        $('#cp_ext_new_child_row').hide();
                        $('#cp_new_child_name, #cp_new_child_grade').val('');
                        cpCalcTotal();
                    } else {
                        alert(res.data?.message || R.strings.error);
                    }
                })
                .fail(() => { $('#cp_new_child_loading').hide(); alert(R.strings.error); });
        });

        // Direct payment to customer (no child)
        $(document).on('change', '#cp_ext_pay_customer_direct', function () {
            if ($(this).is(':checked')) {
                $('.cp-ext-student-check').prop('checked', false).prop('disabled', true);
            } else {
                $('.cp-ext-student-check').prop('disabled', false);
            }
            cpCalcTotal();
        });

        // Fee template → pre-fill amount
        $('#cp_fee_template').on('change', function () {
            const amount = $(this).find(':selected').data('amount');
            if (amount !== undefined) { $('#cp_amount').val(parseFloat(amount).toFixed(2)); cpCalcTotal(); }
        });

        $(document).on('change input', '.cp-student-check, .cp-ext-student-check, #cp_amount, #cp_discount', cpCalcTotal);

        // Submit — standalone custom payments page only
        // Guard: if os-hub-data exists we are on the Hub page, skip this handler.
        // os-hub.js handles the Hub context with its own delegated submit handler.
        $(document).on('submit', '#olama-reg-custom-payment-form', function (e) {
            e.preventDefault();

            // Skip on Hub page
            if (document.getElementById('os-hub-data')) return;

            const radioVal = $('input[name="customer_type"]:checked').val();
            const hiddenVal = $('input[name="customer_type"][type="hidden"]').val();
            const isExternal = (radioVal || hiddenVal) === 'external';
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
                // child_ids[] are already serialized from checkboxes (name="child_ids[]")
                // If direct payment, remove any child_ids and signal no children
                if (isDirect) {
                    // Strip child_ids from serialized and send empty
                    formData = formData.replace(/child_ids%5B%5D=[^&]*/g, '').replace(/child_ids%5B%5D/g, '');
                }
            }

            $.post(R.ajaxurl, 'action=olama_reg_save_custom_payment&nonce=' + R.nonce + '&' + formData)
                .done(res => {
                    if (res.success) {
                        $msg.html('<div class="notice notice-success inline" style="padding:10px;"><p>' + res.data.message + '</p></div>').fadeIn();
                        $form[0].reset();
                        if (typeof $.fn.select2 !== 'undefined') {
                            $('#cp_family_search, #cp_ext_customer_search').val(null).trigger('change');
                        }
                        $('#cp_ext_students_list').empty();
                        $('#cp_ext_children_section').hide();
                        $('#cp_ext_no_customer_msg').show();
                        cpCalcTotal();

                        // Open receipt in new tab
                        const invoiceId = res.data.payment_id || (res.data.invoice_ids && res.data.invoice_ids[0]);
                        if (invoiceId) {
                            const url = new URL(window.location.href);
                            url.searchParams.set('page', 'olama-registration-payments');
                            url.searchParams.set('action', 'print_receipt');
                            url.searchParams.set('id', invoiceId);
                            window.open(url.toString(), '_blank');
                        }
                    } else {
                        $msg.html('<div class="notice notice-error inline" style="padding:10px;"><p>' + (res.data?.message || 'حدث خطأ.') + '</p></div>').fadeIn();
                    }
                })
                .always(() => { $btn.prop('disabled', false); $loading.hide(); });
        });
        if (window.OS_LINKED_AGREEMENT) {
            const agr = window.OS_LINKED_AGREEMENT;
            // 1. Set customer type
            if (agr.payer_type === 'external' || agr.payer_type === 'customer') {
                $('#cp_type_external').prop('checked', true).trigger('change');
                if (agr.payer_id && agr.payer_name) {
                    const newOption = new Option(agr.payer_name, agr.payer_id, true, true);
                    $('#cp_ext_customer_search').append(newOption).trigger('change');
                }
            } else {
                $('#cp_type_internal').prop('checked', true).trigger('change');
                if (agr.payer_id && agr.payer_name) {
                    const newOption = new Option(agr.payer_name, agr.payer_id, true, true);
                    $('#cp_family_search').append(newOption).trigger('change');
                }
            }

            // 2. Set Service Type
            if (agr.activity_type) {
                let serviceMap = {
                    'kindergarten': 'رياض الأطفال',
                    'summer_club': 'النادي الصيفي',
                    'karate': 'كاراتيه'
                };
                let serviceName = serviceMap[agr.activity_type] || agr.activity_type;
                let $srvOpt = $('#cp_service_type option').filter(function () { return $(this).text() === serviceName || $(this).val() === serviceName; });
                if ($srvOpt.length) {
                    $('#cp_service_type').val($srvOpt.val());
                } else {
                    $('#cp_service_type').append(new Option(serviceName, serviceName, true, true));
                }
            }

            // 3. Set amounts and check children after AJAX
            $(document).ajaxStop(function () {
                if (!window._os_linked_agr_processed) {
                    let childCount = 0;
                    if (agr.participants && agr.participants.length) {
                        $('.cp-student-check, .cp-ext-student-check').each(function () {
                            let val = $(this).val();
                            if (agr.participants.includes(parseInt(val)) || agr.participants.includes(val)) {
                                $(this).prop('checked', true);
                                childCount++;
                            } else {
                                $(this).prop('checked', false).prop('disabled', true);
                            }
                        });
                    }

                    if (childCount === 0) childCount = 1;

                    let perChild = childCount > 0 ? (parseFloat(agr.amount) / childCount) : parseFloat(agr.amount);
                    let perChildDiscount = childCount > 0 ? (parseFloat(agr.discount) / childCount) : parseFloat(agr.discount);

                    $('#cp_amount').val(perChild.toFixed(2));
                    $('#cp_discount').val(perChildDiscount.toFixed(2));
                    $('select[name="payment_method"]').val('cash');

                    cpCalcTotal();
                    window._os_linked_agr_processed = true;
                }
            });
        }

    }

    // Auto-open invoice details if action=view and id is in URL
    $(function () {
        setTimeout(function () {
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get('action');
            const id = urlParams.get('id');
            const page = urlParams.get('page');
            if (page === 'olama-registration-invoices' && action === 'view' && id) {
                const $btn = $(`.olama-reg-view-invoice-btn[data-id="${id}"]`);
                if ($btn.length) {
                    $btn.trigger('click');
                } else {
                    ajax('olama_reg_get_invoice', { id })
                        .done(res => {
                            if (res.success && res.data.invoice) {
                                const inv = res.data.invoice;

                                $('#drawer-invoice-number').text(inv.invoice_number);
                                $('#drawer-total-val').text(parseFloat(inv.total).toFixed(2) + ' د.أ');
                                $('#drawer-discount-val').text(parseFloat(inv.discount || 0).toFixed(2) + ' د.أ');
                                $('#drawer-paid-val').text(parseFloat(inv.amount_paid).toFixed(2) + ' د.أ');
                                $('#drawer-balance-val').text(parseFloat(inv.balance).toFixed(2) + ' د.أ');
                                $('#drawer-debit-notes-val').text(parseFloat(inv.debit_notes_total || 0).toFixed(2) + ' د.أ');
                                $('#drawer-credit-notes-val').text(parseFloat(inv.credit_notes_total || 0).toFixed(2) + ' د.أ');
                                $('#drawer-family-uid').text(inv.family_uid);
                                $('#drawer-issue-date').text(inv.issue_date);
                                $('#drawer-due-date').text(inv.due_date || '—');

                                let statusClass = 'olama-reg-badge--inactive';
                                let statusLabel = 'مسودة';
                                switch (inv.status) {
                                    case 'issued':
                                        statusClass = 'olama-reg-badge--active';
                                        statusLabel = 'صادرة';
                                        break;
                                    case 'partial':
                                        statusClass = 'olama-reg-badge--active';
                                        statusLabel = 'جزئية';
                                        break;
                                    case 'paid':
                                        statusClass = 'olama-reg-badge--active';
                                        statusLabel = 'مدفوعة';
                                        break;
                                    case 'overdue':
                                        statusClass = 'olama-reg-badge--blacklist';
                                        statusLabel = 'متأخرة';
                                        break;
                                    case 'cancelled':
                                        statusClass = 'olama-reg-badge--inactive';
                                        statusLabel = 'ملغاة';
                                        break;
                                }
                                const overdueBadge = inv.is_overdue && inv.status !== 'overdue'
                                    ? ' <span class="olama-reg-badge olama-reg-badge--blacklist">متأخرة</span>'
                                    : '';
                                $('#drawer-status-badge').html(`<span class="olama-reg-badge ${statusClass}">${statusLabel}</span>${overdueBadge}`);

                                // Populate items
                                const $itemsBody = $('#drawer-items-table tbody');
                                $itemsBody.empty();
                                if (inv.items && inv.items.length) {
                                    inv.items.forEach(item => {
                                        $itemsBody.append(`
                                        <tr>
                                            <td>${item.description}</td>
                                            <td style="text-align:center;">${parseFloat(item.quantity).toFixed(0)}</td>
                                            <td>${parseFloat(item.unit_price).toFixed(2)}</td>
                                            <td style="font-weight:700;">${parseFloat(item.line_total).toFixed(2)}</td>
                                        </tr>`);
                                    });
                                }

                                // Populate installments
                                const $instBody = $('#drawer-installments-table tbody');
                                $instBody.empty();
                                if (inv.installments && inv.installments.length) {
                                    inv.installments.forEach(inst => {
                                        let instStatusClass = 'olama-reg-badge--inactive';
                                        let instStatusLabel = 'معلق';
                                        switch (inst.status) {
                                            case 'unpaid':
                                            case 'pending':
                                                instStatusClass = 'olama-reg-badge--inactive';
                                                instStatusLabel = 'غير مسدد';
                                                break;
                                            case 'paid':
                                                instStatusClass = 'olama-reg-badge--active';
                                                instStatusLabel = 'مسدد';
                                                break;
                                            case 'partially_paid':
                                            case 'partial':
                                                instStatusClass = 'olama-reg-badge--active';
                                                instStatusLabel = 'جزئي';
                                                break;
                                            case 'overdue':
                                                instStatusClass = 'olama-reg-badge--blacklist';
                                                instStatusLabel = 'متأخر';
                                                break;
                                        }

                                        $instBody.append(`
                                        <tr>
                                            <td>${inst.installment_no}</td>
                                            <td>${inst.due_date}</td>
                                            <td style="font-weight:700;">${parseFloat(inst.amount_due).toFixed(2)}</td>
                                            <td>${parseFloat(inst.amount_paid).toFixed(2)}</td>
                                            <td><span class="olama-reg-badge ${instStatusClass}">${instStatusLabel}</span></td>
                                        </tr>`);
                                    });
                                } else {
                                    $instBody.append('<tr><td colspan="5" style="text-align:center; color:#6b7280;">لا يوجد جدول استحقاق مرتبط بهذه الفاتورة.</td></tr>');
                                }

                                // Payment record trigger
                                const $drawerActions = $('#olama-reg-invoice-drawer').find('.olama-reg-form-actions');
                                $drawerActions.find('.olama-reg-pay-invoice-trigger, .olama-reg-adjustment-trigger').remove();
                                const policy = inv.policy || {};
                                if (policy.can_create_debit_note) {
                                    $drawerActions.prepend(`
                                        <button type="button" class="button olama-reg-adjustment-trigger" data-type="debit" data-id="${inv.id}">
                                            إشعار مدين
                                        </button>
                                    `);
                                }
                                if (policy.can_create_credit_note) {
                                    $drawerActions.prepend(`
                                        <button type="button" class="button olama-reg-adjustment-trigger" data-type="credit" data-id="${inv.id}">
                                            إشعار دائن
                                        </button>
                                    `);
                                }
                                if (policy.can_record_payment) {
                                    $drawerActions.prepend(`
                                        <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-reg-pay-invoice-trigger" 
                                                data-id="${inv.id}" data-no="${inv.invoice_number}" data-bal="${inv.balance}" data-family="${inv.family_uid}">
                                            تسجيل دفعة ماليّة
                                        </button>
                                    `);
                                }

                                $('#olama-reg-invoice-drawer').fadeIn(200);
                            }
                        });
                }
            }
        }, 250);
    });

    // ── Agreements Module ────────────────────────────────────────────────────

    // Agreement Tabs
    $(document).on('click', '.os-nav-tabs .nav-tab', function (e) {
        e.preventDefault();
        if ($(this).hasClass('os-disabled')) return;

        $('.os-nav-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        const target = $(this).attr('href');
        $('.os-tab-content').hide().removeClass('active');
        $(target).show().addClass('active');

        // Update URL hash
        if (history.replaceState) {
            history.replaceState(null, '', target);
        }
    });

    // Auto-open tab from hash on load
    $(document).ready(function () {
        if (window.location.hash && window.location.hash.startsWith('#tab-')) {
            const $targetTab = $('.os-nav-tabs .nav-tab[href="' + window.location.hash + '"]');
            if ($targetTab.length && !$targetTab.hasClass('os-disabled')) {
                $targetTab.trigger('click');
            }
        }
    });

    // Init payer select2
    function initAgrPayerSelect() {
        const $payer = $('#os-agr-payer');
        if (!$payer.length) return;

        const payerType = $('input[name="payer_type"]:checked').val() || 'customer';

        $payer.select2({
            dir: 'rtl',
            ajax: {
                url: R.ajaxurl,
                type: 'POST',
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'olama_reg_agr_search_payer',
                        nonce: R.nonce,
                        q: params.term,
                        payer_type: payerType
                    };
                },
                processResults: function (data) {
                    return { results: data.success ? data.data.results : [] };
                }
            },
            placeholder: 'ابحث عن الجهة الدافعة...',
            minimumInputLength: 2
        });
    }

    if ($('#os-agreement-app').length) {
        initAgrPayerSelect();
        $('#os-agr-participant').select2({ dir: 'rtl', placeholder: 'اختر المشترك', multiple: true });
        $('#os-agr-activity').select2({ dir: 'rtl' });
    }

    $(document).on('change', 'input[name="payer_type"]', function () {
        $('#os-agr-payer').empty().trigger('change');
        $('#os-agr-participant').empty().trigger('change');
        initAgrPayerSelect(); // re-init with new type
    });

    let hasPreloadedParticipants = $('#os-agr-participant option').length > 0;

    $(document).on('change', '#os-agr-payer', function (e, isInit) {
        if (hasPreloadedParticipants) {
            hasPreloadedParticipants = false; // consume the page-load trigger
            return;
        }
        if (isInit) return; // Prevent clearing on init if we have preloaded data
        const payerId = $(this).val();
        const payerType = $('input[name="payer_type"]:checked').val();
        const $participant = $('#os-agr-participant');

        $participant.empty().trigger('change');
        if (!payerId) return;

        ajax('olama_reg_agr_get_participants', { payer_type: payerType, payer_id: payerId })
            .done(res => {
                if (res.success && res.data.results) {
                    res.data.results.forEach(p => {
                        $participant.append(new Option(p.text, p.id, false, false));
                    });
                    $participant.trigger('change');
                }
            });
    });

    $(document).on('click', '#os-btn-save-fees-tab, #os-btn-save-header-bottom', function () {
        $('#os-form-agreement-header').trigger('submit');
    });

    $(document).on('input change', '#os-form-agreement-header :input', function () {
        $('#os-agreement-app').attr('data-header-saved', '0').data('header-saved', '0');
        if (typeof syncAgreementWorkspaceActions === 'function') {
            syncAgreementWorkspaceActions();
        }
    });

    $(document).on('submit', '#os-form-agreement-header', function (e) {
        e.preventDefault();
        const $agreementForm = $(this);
        const $btn = $('#os-btn-save-header, #os-btn-save-header-bottom, #os-btn-save-fees-tab').filter(':visible').first();
        const formData = {};
        $agreementForm.serializeArray().forEach(item => {
            if (item.name.endsWith('[]')) {
                const cleanName = item.name.replace('[]', '');
                if (!formData[cleanName]) formData[cleanName] = [];
                formData[cleanName].push(item.value);
            } else {
                formData[item.name] = item.value;
            }
        });

        ajax('olama_reg_agr_save_header', formData, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                    jQuery('#os-agreement-app').attr('data-header-saved', '1').data('header-saved', '1');
                    if (typeof syncAgreementWorkspaceActions === 'function') {
                        syncAgreementWorkspaceActions();
                    }
                    
                    if (res.data.agreement_number) {
                        // 1. Update main page heading if editing
                        jQuery('.wp-heading-inline').each(function() {
                            let text = jQuery(this).text();
                            if (text.indexOf('إضافة عقد جديد') !== -1 || text.indexOf('تعديل العقد') !== -1) {
                                jQuery(this).text('تعديل العقد #' + res.data.agreement_number);
                            }
                        });
                        
                        // 2. Update modal title inside customer hub
                        jQuery('#olama-reg-agreement-modal .olama-reg-modal-title').html(
                            '<span class="dashicons dashicons-media-text"></span> ' + 
                            'تعديل العقد #' + res.data.agreement_number
                        );
                    }

                    if (formData.status) {
                        const statusClass = formData.status === 'active' ? 'olama-reg-badge--active' : (formData.status === 'draft' ? 'olama-reg-badge--warning' : 'olama-reg-badge--inactive');
                        jQuery('#os-agr-status-badge').removeClass('olama-reg-badge--active olama-reg-badge--warning olama-reg-badge--inactive').addClass(statusClass).text(formData.status);
                    }

                    const isHubEmbedded = $agreementForm.closest('#os-hub-original-form-modal').length > 0;
                    const isModal = jQuery('#olama-reg-agreement-modal').is(':visible') || isHubEmbedded;
                    if (isModal) {
                        // We are inside the Customer Hub modal
                        $agreementForm.find('input[name="id"]').val(res.data.id);
                        jQuery('#os-agreement-app').attr('data-id', res.data.id);
                        jQuery('#os-agr-fees-table').data('agr-id', res.data.id).attr('data-agr-id', res.data.id);
                        
                        if (isHubEmbedded) {
                            jQuery('.os-nav-tabs .nav-tab[href="#tab-fees"]').removeClass('os-disabled');
                            jQuery('.os-nav-tabs .nav-tab[href="#tab-fees"]').trigger('click');
                            jQuery(document).trigger('osHub:agreementSaved', [jQuery.extend({}, res.data, {
                                wasNew: parseInt(formData.id, 10) === 0
                            })]);
                        } else {
                            // Enable tabs in modal
                            jQuery('#modal-tab-link-fees').removeClass('os-disabled');

                            // Go to Fees tab
                            jQuery('#modal-tab-link-fees').trigger('click');
                        }
                    } else if (formData.id == 0 && res.data.id) {
                        setTimeout(() => {
                            window.location.href = R.ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=olama-registration-agreements&action=edit&id=' + res.data.id;
                        }, 1000);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail(() => showNotice(R.strings.error, true));
    });

    // ── Agreements: Fees ─────────────────────────────────────────────────────

    function agreementFlag(attrName) {
        const $app = $('#os-agreement-app');
        if (!$app.length) return true;
        const raw = $app.attr('data-' + attrName);
        return raw === undefined || raw === '1' || raw === 'true';
    }

    function canEditAgreementFinancials() {
        return agreementFlag('can-edit-financial');
    }

    function canRescheduleAgreementInstallments() {
        return agreementFlag('can-reschedule');
    }

    function canCreateAgreementAmendment() {
        return agreementFlag('can-create-amendment');
    }

    function showAgreementLockNotice(type) {
        const msg = type === 'schedule'
            ? 'لا يمكن تعديل توزيع الاستحقاق بعد بدء التحصيل أو وجود قيود مالية مرتبطة.'
            : 'العقد مقفل مالياً ولا يمكن تعديل البنود المالية مباشرة. استخدم إجراء تعديل العقد.';
        showNotice(msg, true);
    }

    $(function () {
        if (!canEditAgreementFinancials()) {
            $('.os-agr-save-fee, .os-agr-delete-fee').prop('disabled', true);
            if (canCreateAgreementAmendment() && $('#os-agr-create-amendment').length === 0) {
                const $notice = $('#os-agreement-app .notice-warning.inline').first();
                if ($notice.length) {
                    $notice.append('<p style="margin:12px 0 0;"><button type="button" class="button button-primary" id="os-agr-create-amendment" data-action="add-fee">تعديل مالي على العقد</button></p>');
                }
            }
        }
        if (!canRescheduleAgreementInstallments()) {
            $('#os-agr-due-count, #os-agr-add-due-row, #os-agr-regenerate-due, #os-agr-save-due, .os-agr-delete-due').prop('disabled', true);
        }
    });

    $(document).on('click', '#os-agr-create-amendment, #os-agr-create-amendment-inline', function () {
        const reason = prompt(olamaReg.i18n.amendmentReasonPrompt || 'أدخل سبب التعديل المالي:');
        if (!reason || !String(reason).trim()) {
            showNotice(olamaReg.i18n.amendmentReasonRequired || 'سبب التعديل مطلوب.', true);
            return;
        }

        // Navigate to fees tab
        $('.os-nav-tabs .nav-tab[href="#tab-fees"]').trigger('click');

        // Enable fee amendment mode
        window.osAgreementFeeAmendmentMode = {
            active: true,
            reason: String(reason).trim(),
        };

        // Show banner
        const $banner = $('#os-agr-fee-amendment-banner');
        if ($banner.length) {
            $banner.show();
            $('#os-agr-fee-amendment-reason').val(window.osAgreementFeeAmendmentMode.reason);
        }

        // Enable add fee button and save buttons for new rows
        $('#os-agr-add-fee-row').prop('disabled', false);
        $('.os-agr-save-fee').prop('disabled', false);
        
        // Enable due schedule buttons when in amendment mode
        $('#os-agr-due-count, #os-agr-add-due-row, #os-agr-regenerate-due, #os-agr-save-due, .os-agr-delete-due').prop('disabled', false);

        showNotice(olamaReg.i18n.amendmentActivated || 'تم تفعيل وضع التعديل المالي. يمكنك إضافة بند رسوم جديد.');
    });

    $(document).on('click', '#os-agr-fee-amendment-cancel', function () {
        window.osAgreementFeeAmendmentMode = { active: false, reason: '' };
        $('#os-agr-fee-amendment-banner').hide();
        $('#os-agr-fee-amendment-reason').val('');
        if (!canEditAgreementFinancials()) {
            $('#os-agr-add-fee-row').prop('disabled', true);
            $('.os-agr-save-fee').prop('disabled', true);
        }
        if (!canRescheduleAgreementInstallments()) {
            $('#os-agr-due-count, #os-agr-add-due-row, #os-agr-regenerate-due, #os-agr-save-due, .os-agr-delete-due').prop('disabled', true);
        }
        showNotice(olamaReg.i18n.amendmentCancelled || 'تم إلغاء وضع التعديل المالي.');
    });

    $(document).on('click', '#os-agr-close-amendment-modal', function () {
        $('#os-agr-amendment-modal').fadeOut(150);
    });

    $(document).on('input change', '#os-agr-amendment-new', function () {
        const oldVal = parseMoney($('#os-agr-amendment-old').val());
        const newVal = parseMoney($(this).val());
        $('#os-agr-amendment-diff').val((newVal - oldVal).toFixed(3));
    });

    $(document).on('click', '#os-agr-preview-amendment', function () {
        const agreementId = $('#os-agreement-app').data('id') || $('#os-agreement-app').attr('data-id');
        ajax('olama_reg_agr_preview_amendment', {
            agreement_id: agreementId,
            new_total: parseMoney($('#os-agr-amendment-new').val())
        }, $(this)).done(function (res) {
            if (res.success && res.data.preview) {
                const p = res.data.preview;
                $('#os-agr-amendment-old').val(parseFloat(p.old_total || 0).toFixed(3));
                $('#os-agr-amendment-new').val(parseFloat(p.new_total || 0).toFixed(3));
                $('#os-agr-amendment-diff').val(parseFloat(p.difference_amount || 0).toFixed(3));
                showNotice('تم تحديث المعاينة.');
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () {
            showNotice(R.strings.error, true);
        });
    });

    $(document).on('submit', '#os-agr-amendment-form', function (e) {
        e.preventDefault();
        const agreementId = $('#os-agreement-app').data('id') || $('#os-agreement-app').attr('data-id');
        const payload = {
            agreement_id: agreementId,
            amendment_type: $('#os-agr-amendment-type').val(),
            effective_date: $('#os-agr-amendment-date').val(),
            new_total: parseMoney($('#os-agr-amendment-new').val()),
            reason: $('#os-agr-amendment-reason').val(),
            admin_notes: $('#os-agr-amendment-notes').val()
        };
        if (!String(payload.reason || '').trim()) {
            showNotice('سبب التعديل مطلوب.', true);
            return;
        }

        ajax('olama_reg_agr_create_amendment', payload, $('#os-agr-save-amendment')).done(function (res) {
            if (res.success) {
                showNotice(res.data?.message || 'تم إنشاء مسودة تعديل العقد.');
                $('#os-agr-amendment-modal').fadeOut(150, function () {
                    window.location.reload();
                });
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () {
            showNotice(R.strings.error, true);
        });
    });

    function amendmentRowId($btn) {
        return $btn.closest('tr').data('amendment-id') || $btn.closest('tr').attr('data-amendment-id');
    }

    $(document).on('click', '.os-agr-approve-amendment', function () {
        const $btn = $(this);
        const amendmentId = amendmentRowId($btn);
        ajax('olama_reg_agr_approve_amendment', { amendment_id: amendmentId }, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data?.message || 'تم اعتماد التعديل.');
                window.location.reload();
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () { showNotice(R.strings.error, true); });
    });

    $(document).on('click', '.os-agr-post-amendment', function () {
        const $btn = $(this);
        const amendmentId = amendmentRowId($btn);
        ajax('olama_reg_agr_post_amendment', { amendment_id: amendmentId }, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data?.message || 'تم ترحيل التعديل.');
                window.location.reload();
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () { showNotice(R.strings.error, true); });
    });

    $(document).on('click', '.os-agr-reject-amendment', function () {
        const $btn = $(this);
        const amendmentId = amendmentRowId($btn);
        const reason = prompt('أدخل سبب الرفض');
        if (reason === null) return;
        ajax('olama_reg_agr_reject_amendment', { amendment_id: amendmentId, reason }, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data?.message || 'تم رفض التعديل.');
                window.location.reload();
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () { showNotice(R.strings.error, true); });
    });

    $(document).on('click', '.os-agr-cancel-amendment', function () {
        const $btn = $(this);
        const amendmentId = amendmentRowId($btn);
        const reason = prompt('أدخل سبب الإلغاء');
        if (reason === null) return;
        ajax('olama_reg_agr_cancel_amendment', { amendment_id: amendmentId, reason }, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data?.message || 'تم إلغاء التعديل.');
                window.location.reload();
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () { showNotice(R.strings.error, true); });
    });

    $(document).on('click', '#os-agr-create-amendment-legacy', function () {
        const agreementId = $('#os-agreement-app').data('id') || $('#os-agreement-app').attr('data-id');
        const currentTotal = parseMoney($('#os-agr-total-label').text()) || parseMoney($('#os-agr-due-net').text());
        const newTotalRaw = prompt('أدخل القيمة الجديدة للعقد', currentTotal ? currentTotal.toFixed(3) : '');
        if (newTotalRaw === null) return;
        const reason = prompt('أدخل سبب التعديل المالي');
        if (reason === null) return;
        if (!String(reason).trim()) {
            showNotice('سبب التعديل مطلوب.', true);
            return;
        }

        const $btn = $(this);
        ajax('olama_reg_agr_create_amendment', {
            agreement_id: agreementId,
            amendment_type: 'correction_error',
            effective_date: new Date().toISOString().slice(0, 10),
            new_total: parseMoney(newTotalRaw),
            reason: reason
        }, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data?.message || 'تم إنشاء مسودة تعديل العقد.');
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        }).fail(function () {
            showNotice(R.strings.error, true);
        });
    });

    $(document).on('click', '#os-agr-add-fee-row', function () {
        if (!canEditAgreementFinancials() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active)) {
            showAgreementLockNotice('financial');
            return;
        }
        const $template = $('#os-agr-fee-row-template').find('tr').clone();
        
        // Dynamically populate child_id options from window.payerChildren if available
        const $childSelect = $template.find('[name="child_id"]');
        if ($childSelect.length && window.payerChildren && Array.isArray(window.payerChildren)) {
            $childSelect.empty().append(new Option('اختر المشترك', ''));
            window.payerChildren.forEach(function (child) {
                $childSelect.append(new Option(child.text, child.id));
            });
            if (window.payerChildren.length === 1) {
                $childSelect.val(String(window.payerChildren[0].id));
            }
        }

        $('#os-agr-fees-table tbody').append($template);
        syncAgreementFeeTemplateOptions($template);
        // Enable save button for new rows when in amendment mode
        if (window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active) {
            $template.find('.os-agr-save-fee').prop('disabled', false);
        }
        const $feeSelect = $template.find('.os-agr-fee-template-select');
        if (!$feeSelect.val()) {
            const firstValue = $feeSelect.find('option:not(:disabled):not([hidden])').filter(function () {
                return $(this).val();
            }).first().val();
            if (firstValue) {
                $feeSelect.val(firstValue);
            }
        }
        $feeSelect.trigger('change');
        initEmbeddedAgreementFeeRowSelects($template);
        initDatepickers($('#os-agr-fees-table tbody tr').last());
    });

    function renderAgreementFeeBreakdown($tr, rawBreakdown) {
        const $container = $tr.find('.os-agr-fee-breakdown').empty();
        let items = rawBreakdown;

        if (typeof items === 'string') {
            try {
                items = JSON.parse(items);
            } catch (error) {
                items = [];
            }
        }

        if (!Array.isArray(items)) return;

        items.forEach(function (item) {
            const amount = parseFloat(item.amount) || 0;
            const $line = $('<span>');
            $('<span>').text(item.description || '').appendTo($line);
            $('<b>').text(amount.toFixed(3)).appendTo($line);
            $container.append($line);
        });
    }

    // A fee template is one agreement line; its items are a read-only breakdown.
    $(document).on('change', '.os-agr-fee-template-select', function () {
        const $option = $(this).find('option:selected');
        const $tr = $(this).closest('tr');
        const name = $option.data('name');
        const amount = $option.data('amount');

        if (name) {
            $tr.find('[name="label"]').val(name);
            $tr.find('.os-agr-fee-template-name').text(name);
        }

        if (amount !== undefined) {
            $tr.find('[name="amount"]').val(amount).trigger('input');
        }

        renderAgreementFeeBreakdown($tr, $option.attr('data-breakdown') || '[]');
    });

    $(function () {
        $('.os-agr-fee-template-select').each(function () {
            const $select = $(this);
            const $tr = $select.closest('tr');
            const currentAmount = parseFloat($tr.find('[name="amount"]').val()) || 0;
            const templateAmount = parseFloat($select.find('option:selected').data('amount')) || 0;
            if (currentAmount <= 0 && templateAmount > 0) {
                $select.trigger('change');
            }
        });
    });

    $(document).on('input', '.os-agr-fee-calc', function () {
        const $tr = $(this).closest('tr');
        const amt = parseFloat($tr.find('[name="amount"]').val()) || 0;
        const dsc = parseFloat($tr.find('[name="discount"]').val()) || 0;
        const net = Math.max(0, amt - dsc);
        $tr.find('.os-agr-fee-net').text(net.toFixed(3));
    });

    $(document).on('click', '.os-agr-save-fee', function () {
        if (!canEditAgreementFinancials() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active)) {
            showAgreementLockNotice('financial');
            return;
        }
        const $btn = $(this);
        const $tr = $btn.closest('tr');
        const agrId = $('#os-agr-fees-table').data('agr-id');
        const data = {
            id: $tr.attr('data-fee-id'),
            agreement_id: agrId,
            fee_category: $tr.find('[name="fee_category"]').val(),
            child_id: $tr.find('[name="child_id"]').val(),
            label: $tr.find('[name="label"]').val(),
            amount: $tr.find('[name="amount"]').val(),
            discount: $tr.find('[name="discount"]').val(),
            due_date: $tr.find('[name="due_date"]').val(),
        };

        // If in fee amendment mode, pass amendment data for new fees only
        if (window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active) {
            const feeId = $tr.attr('data-fee-id');
            if (!feeId || feeId === '0') {
                data.amendment_reason = window.osAgreementFeeAmendmentMode.reason;
                data.amendment_type = 'add_fee';
            }
        }

        ajax('olama_reg_agr_save_fee', data, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                    if (res.data.id) $tr.attr('data-fee-id', res.data.id);
                    if (res.data.total !== undefined) {
                        $('#os-agr-total-label').text(parseFloat(res.data.total).toFixed(3));
                        
                        let perChild = 0;
                        $('#os-agr-fees-table tbody tr').each(function() {
                            const net = parseFloat($(this).find('.os-agr-fee-net').text()) || 0;
                            perChild += net;
                        });
                        $('#os-agr-per-child-total').text(perChild.toFixed(3));
                    }
                    if (Array.isArray(res.data.schedule)) {
                        renderAgreementDueSchedule(res.data.schedule);
                        markAgreementDueScheduleSaved(true);
                    }
                    // Reload page if amendment was created so user sees it in the log
                    if (data.amendment_reason) {
                        setTimeout(() => window.location.reload(), 1200);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            })
            .fail((xhr, status, error) => {
                console.error('Save fee failed:', status, error, xhr.responseText);
                showNotice(R.strings.error + ' (HTTP ' + xhr.status + ')', true);
            });
    });

    $(document).on('click', '.os-agr-delete-fee', function () {
        if (!canEditAgreementFinancials()) {
            showAgreementLockNotice('financial');
            return;
        }
        if (!confirm(R.strings.confirmDelete)) return;
        const $tr = $(this).closest('tr');
        const id = $tr.attr('data-fee-id');
        const agrId = $('#os-agr-fees-table').data('agr-id');

        if (!id || id == 0) {
            $tr.remove();
            return;
        }

        ajax('olama_reg_agr_delete_fee', { id, agreement_id: agrId })
            .done(res => {
                if (res.success) {
                    $tr.remove();
                    if (res.data.total !== undefined) {
                        $('#os-agr-total-label').text(parseFloat(res.data.total).toFixed(3));
                        
                        let perChild = 0;
                        $('#os-agr-fees-table tbody tr').each(function() {
                            const net = parseFloat($(this).find('.os-agr-fee-net').text()) || 0;
                            perChild += net;
                        });
                        $('#os-agr-per-child-total').text(perChild.toFixed(3));
                    }
                    if (Array.isArray(res.data.schedule)) {
                        renderAgreementDueSchedule(res.data.schedule);
                        markAgreementDueScheduleSaved(true);
                    }
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    // ── Agreements: Cancel Fee Modal ──────────────────────────────────────────

    $(document).on('click', '.os-agr-cancel-fee-trigger', function () {
        const $tr = $(this).closest('tr');
        const id = $tr.attr('data-fee-id');
        
        if (!id || id == 0) {
            $tr.remove();
            return;
        }

        const label = $tr.find('input[name="label"]').val() || '';
        const amount = parseFloat($tr.find('input[name="amount"]').val() || 0).toFixed(3);
        const discount = parseFloat($tr.find('input[name="discount"]').val() || 0).toFixed(3);
        const net = parseFloat($tr.find('.os-agr-fee-net').text() || 0).toFixed(3);

        $('#os-cancel-fee-id-input').val(id);
        $('#os-cancel-fee-label-text').text(label);
        $('#os-cancel-fee-amount-text').text(amount + ' JD');
        $('#os-cancel-fee-discount-text').text(discount + ' JD');
        $('#os-cancel-fee-net-text').text(net + ' JD');
        
        $('#os-cancel-fee-reason').val('');
        $('#os-cancel-fee-notes').val('');
        
        $('#os-cancel-fee-modal').show();
    });

    $(document).on('click', '#os-close-cancel-fee-modal', function () {
        $('#os-cancel-fee-modal').hide();
    });

    $(document).on('submit', '#os-cancel-fee-form', function (e) {
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const data = {
            id: $('#os-cancel-fee-id-input').val(),
            agreement_id: $form.find('input[name="agreement_id"]').val(),
            reason: $('#os-cancel-fee-reason').val(),
            effective_date: $('#os-cancel-fee-date').val(),
            notes: $('#os-cancel-fee-notes').val(),
        };

        $btn.prop('disabled', true);
        ajax('olama_reg_agr_delete_fee', data)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message || 'تم إلغاء البند بنجاح.');
                    $('#os-cancel-fee-modal').hide();
                    if (res.data.reload) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    showNotice(res.data?.message || 'حدث خطأ أثناء إلغاء البند.', true);
                    $btn.prop('disabled', false);
                }
            })
            .fail(() => {
                showNotice('حدث خطأ في الشبكة.', true);
                $btn.prop('disabled', false);
            });
    });

    // ── Agreements: Clauses ──────────────────────────────────────────────────

    function collectAgreementDueLines() {
        const lines = [];
        $('#os-agr-due-table tbody tr').each(function () {
            const dueDate = $(this).find('.os-agr-due-date').val();
            const amount = parseMoney($(this).find('.os-agr-due-amount').val());
            if (dueDate && amount > 0) {
                lines.push({ due_date: dueDate, amount: amount });
            }
        });
        return lines;
    }

    function updateAgreementDueTotals() {
        let total = 0;
        let installmentNo = 0;
        $('#os-agr-due-table tbody tr').each(function () {
            if ($(this).hasClass('os-agr-first-payment-row')) {
                $(this).find('.os-agr-due-no').text('الدفعة الأولى');
            } else {
                installmentNo++;
                $(this).find('.os-agr-due-no').text(installmentNo);
            }
            total += parseMoney($(this).find('.os-agr-due-amount').val());
        });

        const net = parseMoney($('#os-agr-total-label').text()) || parseMoney($('#os-agr-due-net').text());
        const diff = net - total;
        $('#os-agr-due-net').text(net.toFixed(2));
        $('#os-agr-due-total').text(total.toFixed(2));
        $('#os-agr-due-diff').text(diff.toFixed(2));
        $('#os-agr-due-warning').toggle(Math.abs(diff) > 0.009);
        syncAgreementWorkspaceActions();
    }

    function markAgreementDueScheduleSaved(isSaved) {
        $('#os-agreement-app').attr('data-due-saved', isSaved ? '1' : '0').data('due-saved', isSaved ? '1' : '0');
        syncAgreementWorkspaceActions();
    }

    function isAgreementDueScheduleSavedAndBalanced() {
        const headerSaved = String($('#os-agreement-app').attr('data-header-saved') || '0') === '1';
        const dueSaved = String($('#os-agreement-app').attr('data-due-saved') || '0') === '1';
        const diff = Math.abs(parseMoney($('#os-agr-due-diff').text()));
        return headerSaved && dueSaved && diff <= 0.009 && !$('#os-agr-due-warning').is(':visible');
    }

    function syncAgreementWorkspaceActions() {
        const ready = isAgreementDueScheduleSavedAndBalanced();
        const hasInvoice = String($('#os-agreement-app').attr('data-has-invoice') || '0') === '1';
        $('.os-agr-complete-agreement-trigger').prop('disabled', !ready);
        $('.os-agr-pay-requires-saved-due').prop('disabled', !(ready && hasInvoice));
    }

    function renderAgreementDueSchedule(schedule) {
        const $tbody = $('#os-agr-due-table tbody');
        $tbody.empty();
        const scheduleLocked = !canRescheduleAgreementInstallments() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active);
        (schedule || []).forEach(function (line) {
            const amount = parseMoney(line.amount_due || 0);
            const paid = parseMoney(line.amount_paid || 0);
            const remaining = Math.max(0, amount - paid);
            const isFirstPayment = line.is_first_payment === true || String(line.is_first_payment) === '1';
            const rowLocked = scheduleLocked || paid > 0 || isFirstPayment;
            const rowDisabledAttr = rowLocked ? ' disabled' : '';
            const deleteButton = rowLocked ? '' : '<button type="button" class="button button-small os-agr-delete-due" aria-label="حذف القسط"><span class="dashicons dashicons-trash"></span></button>';
            const displayNo = isFirstPayment ? 'الدفعة الأولى' : (line.display_installment_no || line.installment_no || '');
            $tbody.append(`
                <tr${isFirstPayment ? ' class="os-agr-first-payment-row"' : ''}>
                    <td class="os-agr-due-no">${displayNo}</td>
                    <td><input type="text" class="os-datepicker os-agr-due-date" value="${line.due_date || ''}" style="width:100%;"${rowDisabledAttr}></td>
                    <td><input type="number" step="0.01" min="0.01" class="os-agr-due-amount" value="${amount.toFixed(2)}" style="width:100%;"${rowDisabledAttr}></td>
                    <td>${paid.toFixed(2)}</td>
                    <td>${remaining.toFixed(2)}</td>
                    <td>${line.status || 'unpaid'}</td>
                    <td>${deleteButton}</td>
                </tr>
            `);
        });
        $('#os-agr-due-count-summary').text((schedule || []).filter(line => !(line.is_first_payment === true || String(line.is_first_payment) === '1')).length);
        initDatepickers($tbody);
        updateAgreementDueTotals();
    }

    $(document).on('input change', '.os-agr-due-date, .os-agr-due-amount', function () {
        markAgreementDueScheduleSaved(false);
        updateAgreementDueTotals();
    });

    $(document).on('click', '#os-agr-add-due-row', function () {
        if (!canRescheduleAgreementInstallments() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active)) {
            showAgreementLockNotice('schedule');
            return;
        }
        const nextNo = $('#os-agr-due-table tbody tr').length + 1;
        $('#os-agr-due-table tbody').append(`
            <tr>
                <td class="os-agr-due-no">${nextNo}</td>
                <td><input type="text" class="os-datepicker os-agr-due-date" value="" style="width:100%;"></td>
                <td><input type="number" step="0.01" min="0.01" class="os-agr-due-amount" value="0.00" style="width:100%;"></td>
                <td>0.00</td>
                <td>0.00</td>
                <td>unpaid</td>
                <td><button type="button" class="button button-small os-agr-delete-due" aria-label="حذف القسط"><span class="dashicons dashicons-trash"></span></button></td>
            </tr>
        `);
        initDatepickers($('#os-agr-due-table tbody tr').last());
        markAgreementDueScheduleSaved(false);
        updateAgreementDueTotals();
    });

    $(document).on('click', '.os-agr-delete-due', function () {
        if (!canRescheduleAgreementInstallments() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active)) {
            showAgreementLockNotice('schedule');
            return;
        }
        $(this).closest('tr').remove();
        if ($('#os-agr-due-table tbody tr').length === 0) {
            $('#os-agr-add-due-row').trigger('click');
            $('#os-agr-due-table tbody tr:last .os-agr-due-amount').val(parseMoney($('#os-agr-due-net').text()).toFixed(2));
        }
        markAgreementDueScheduleSaved(false);
        updateAgreementDueTotals();
    });

    $(document).on('click', '#os-agr-save-due', function () {
        if (!canRescheduleAgreementInstallments() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active)) {
            showAgreementLockNotice('schedule');
            return;
        }
        const $btn = $(this);
        const agrId = $('#os-agr-due-table').data('agr-id') || $('#os-agr-fees-table').data('agr-id');
        const payload = {
            agreement_id: agrId,
            lines: JSON.stringify(collectAgreementDueLines())
        };
        if (window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active) {
            payload.amendment_reason = window.osAgreementFeeAmendmentMode.reason;
            payload.amendment_type = 'reschedule';
        }
        ajax('olama_reg_agr_save_due_schedule', payload, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data.message);
                renderAgreementDueSchedule(res.data.schedule);
                markAgreementDueScheduleSaved(true);
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        });
    });

    $(document).on('click', '#os-agr-regenerate-due', function () {
        if (!canRescheduleAgreementInstallments() && !(window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active)) {
            showAgreementLockNotice('schedule');
            return;
        }
        const $btn = $(this);
        const agrId = $('#os-agr-due-table').data('agr-id') || $('#os-agr-fees-table').data('agr-id');
        const count = parseInt($('#os-agr-due-count').val(), 10) || 8;
        const payload = { agreement_id: agrId, count };
        if (window.osAgreementFeeAmendmentMode && window.osAgreementFeeAmendmentMode.active) {
            payload.amendment_reason = window.osAgreementFeeAmendmentMode.reason;
            payload.amendment_type = 'reschedule';
        }
        ajax('olama_reg_agr_generate_due_schedule', payload, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data.message);
                renderAgreementDueSchedule(res.data.schedule);
                markAgreementDueScheduleSaved(false);
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        });
    });

    $(document).on('click', '.os-agr-complete-agreement-trigger', function () {
        const $btn = $(this);
        const agrId = $('#os-agr-due-table').data('agr-id') || $('#os-agr-fees-table').data('agr-id');
        updateAgreementDueTotals();
        if (!isAgreementDueScheduleSavedAndBalanced()) {
            showNotice('يرجى حفظ جدول توزيع الاستحقاق قبل إكمال العقد وإنشاء الفاتورة.', true);
            return;
        }
        if ($('#os-agr-due-warning').is(':visible')) {
            showNotice('مجموع الاستحقاقات لا يساوي صافي العقد. يرجى تعديل توزيع الاستحقاق قبل الحفظ.', true);
            return;
        }
        const templateId = parseInt($('#os-agr-contract-template').val(), 10) || 0;
        if (!templateId) {
            showNotice('يجب اختيار نموذج العقد وحفظ البيانات قبل إكمال العقد.', true);
            return;
        }
        ajax('olama_reg_agr_complete', {
            agreement_id: agrId,
            lines: JSON.stringify(collectAgreementDueLines())
        }, $btn).done(function (res) {
            if (res.success) {
                showNotice(res.data.message);
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                showNotice(res.data?.message || R.strings.error, true);
            }
        });
    });

    updateAgreementDueTotals();

    $(document).on('change', '#os-agr-clause-bank-select', function () {
        const text = $(this).val();
        if (text) {
            $('#os-agr-new-clause').val(text);
            $(this).val(''); // Reset selection
        }
    });

    $(document).on('click', '#os-agr-add-clause', function () {
        const $btn = $(this);
        const agrId = $btn.data('agr-id') || $btn.attr('data-agr-id');
        const text = $('#os-agr-new-clause').val().trim();

        if (!text) return;

        if (!agrId || agrId === '0' || agrId === 0) {
            $('#os-agr-new-clause').val('');
            const tempId = 'temp-' + Date.now() + Math.random().toString(36).substr(2, 5);
            const li = `
            <li data-clause-id="${tempId}"
                style="background:#fff; border:1px solid #e0c090; border-radius:6px; padding:15px; margin-bottom:10px; cursor:move; box-shadow:0 2px 5px rgba(0,0,0,0.02);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <span class="dashicons dashicons-menu" style="color:#ccc; margin-left:10px; cursor:grab; margin-top:5px;"></span>
                    <textarea class="os-agr-clause-text" style="flex-grow:1; margin-left:15px; border:1px solid #eee; border-radius:4px; padding:8px;" rows="2">${text}</textarea>
                    <div style="display:flex; flex-direction:column; gap:5px;">
                        <button type="button" class="olama-reg-btn olama-reg-btn--primary os-agr-save-clause" style="padding:2px 10px; font-size:12px; min-height:28px;">حفظ</button>
                        <button type="button" class="button button-small os-agr-delete-clause" style="color:#c62828; border-color:#ffcdd2; background:#fff;">X</button>
                    </div>
                </div>
            </li>`;
            $('#os-agr-clauses-list').append(li);
            return;
        }

        ajax('olama_reg_agr_add_clause', { agreement_id: agrId, clause_text: text }, $btn)
            .done(res => {
                if (res.success) {
                    $('#os-agr-new-clause').val('');
                    const li = `
                    <li data-clause-id="${res.data.id}"
                        style="background:#fff; border:1px solid #e0c090; border-radius:6px; padding:15px; margin-bottom:10px; cursor:move; box-shadow:0 2px 5px rgba(0,0,0,0.02);">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <span class="dashicons dashicons-menu" style="color:#ccc; margin-left:10px; cursor:grab; margin-top:5px;"></span>
                            <textarea class="os-agr-clause-text" style="flex-grow:1; margin-left:15px; border:1px solid #eee; border-radius:4px; padding:8px;" rows="2">${text}</textarea>
                            <div style="display:flex; flex-direction:column; gap:5px;">
                                <button type="button" class="olama-reg-btn olama-reg-btn--primary os-agr-save-clause" style="padding:2px 10px; font-size:12px; min-height:28px;">حفظ</button>
                                <button type="button" class="button button-small os-agr-delete-clause" style="color:#c62828; border-color:#ffcdd2; background:#fff;">X</button>
                            </div>
                        </div>
                    </li>`;
                    $('#os-agr-clauses-list').append(li);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    $(document).on('click', '.os-agr-save-clause', function () {
        const $btn = $(this);
        const $li = $btn.closest('li');
        const id = $li.data('clause-id');
        const text = $li.find('.os-agr-clause-text').val().trim();

        if (String(id).startsWith('temp-')) {
            showNotice('تم حفظ التعديل محلياً');
            return;
        }

        ajax('olama_reg_agr_save_clause', { id, clause_text: text }, $btn)
            .done(res => {
                if (res.success) {
                    showNotice(res.data.message);
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    $(document).on('click', '.os-agr-delete-clause', function () {
        if (!confirm(R.strings.confirmDelete)) return;
        const $li = $(this).closest('li');
        const id = $li.data('clause-id');

        if (String(id).startsWith('temp-')) {
            $li.remove();
            return;
        }

        ajax('olama_reg_agr_delete_clause', { id })
            .done(res => {
                if (res.success) {
                    $li.remove();
                } else {
                    showNotice(res.data?.message || R.strings.error, true);
                }
            });
    });

    // ── Agreements: Invoice Modal ────────────────────────────────────────────

    $(document).on('click', '#os-agr-open-invoice-modal', function () {
        const $btn = $(this);
        // Priority order for finding the agreement ID:
        // 1. Standalone page: #os-agreement-app[data-id]
        // 2. Hub modal — jQuery data cache (set by os-hub.js edit handler)
        // 3. Hub modal — HTML attribute fallback (avoids jQuery cache staleness)
        // 4. Hub modal — hidden form input
        // 5. Button own data-agr-id
        const agrId = parseInt($('#os-agreement-app').data('id'))
                   || parseInt($('#os-agr-fees-table').data('agr-id'))
                   || parseInt($('#os-agr-fees-table').attr('data-agr-id'))
                   || parseInt($('#os-form-agreement-header input[name="id"]').val())
                   || parseInt($btn.attr('data-agr-id'))
                   || 0;
        const status = $('select[name="status"]').val() || $(this).data('status');

        if (!agrId) {
            alert('الرجاء حفظ العقد أولاً.');
            return;
        }

        $btn.prop('disabled', true);

        $.post(R.ajaxurl, {
            action: 'olama_reg_agr_get_unpaid_fees',
            nonce: R.nonce,
            agreement_id: agrId
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                const fees = res.data.fees;
                if (!fees || fees.length === 0) {
                    alert('لا يمكن معالجة الرسوم: لا يوجد بنود رسوم غير مدفوعة في هذا العقد.');
                    return;
                }

                if (status === 'draft') {
                    if (!confirm('العقد لا يزال مسودة، هل تريد المتابعة؟')) {
                        return;
                    }
                }

                const $tbody = $('#os-agr-invoice-form tbody');
                $tbody.empty();

                fees.forEach(fee => {
                    const label = fee.label || fee.fee_category;
                    const amount = parseFloat(fee.net_amount).toFixed(3);

                    const tr = `
                        <tr>
                            <td><input type="checkbox" name="fee_ids[]" value="${fee.id}" class="os-agr-inv-check" checked></td>
                            <td>${label}</td>
                            <td>${amount}</td>
                        </tr>
                    `;
                    $tbody.append(tr);
                });

                // Inject the agreement ID into the hidden field so the submit handler has it
                $('#os-agr-invoice-form input[name="agreement_id"]').val(agrId);

                $('#os-agr-invoice-modal').fadeIn(200);
                $('#os-agr-invoice-form button[type="submit"]').prop('disabled', false);
            } else {
                alert(res.data?.message || 'حدث خطأ.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            alert('حدث خطأ في الاتصال.');
        });
    });

    $(document).on('click', '#os-agr-close-invoice-modal', function () {
        $('#os-agr-invoice-modal').fadeOut(200);
    });

    $(document).on('change', '#os-agr-inv-check-all', function () {
        $('.os-agr-inv-check').prop('checked', this.checked);
    });

    $(document).on('submit', '#os-agr-invoice-form', function (e) {
        e.preventDefault();
        const formDataArray = $(this).serializeArray();

        let agreementId = '';
        let feeIds = [];

        formDataArray.forEach(item => {
            if (item.name === 'agreement_id') agreementId = item.value;
            if (item.name === 'fee_ids[]') feeIds.push(item.value);
        });

        if (!feeIds.length) {
            alert('يرجى تحديد الرسوم التي ترغب بمعالجتها.');
            return;
        }

        const url = R.ajaxurl.replace('admin-ajax.php', 'admin.php') +
            '?page=olama-registration-custom-payments&from_agreement=' + agreementId +
            '&fee_ids=' + feeIds.join(',');

        window.location.href = url;
    });

})(jQuery);
