/**
 * Deactivation feedback modal.
 *
 * Intercepts the "Deactivate" click on the Plugins screen, asks the user for
 * an optional reason, sends it via AJAX, then proceeds to the real deactivate
 * URL. Deactivation is never blocked.
 */
(function ($) {
    'use strict';

    // Show the blocking overlay if jQuery blockUI is available.
    function showLoading($el) {
        if ($el && typeof $el.block === 'function') {
            $el.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
        }
    }

    function feedbackNonce() {
        return (window.donadosuFeedback && donadosuFeedback.nonce) ? donadosuFeedback.nonce : '';
    }

    $(document).ready(function () {
        const $modal = $('.donadosu-deactivation-Modal');
        if ($modal.length) {
            new DonadosuDeactivationModal($modal);
        }

        // "I rather wouldn't say" — send a generic reason, then deactivate.
        $('#donadosu-deactivation-no-reason').on('click', function (e) {
            e.preventDefault();
            showLoading($modal);
            const url = $(this).attr('href');
            $.post(ajaxurl, {
                action: 'donadosu_send_deactivation',
                nonce: feedbackNonce(),
                reason: 'reason-other',
                reason_details: 'other'
            }).always(function () {
                window.location.replace(url);
            });
        });

        // "Send & Deactivate" — send the selected reason + details, then deactivate.
        $('#donadosu-send-deactivation').on('click', function (e) {
            e.preventDefault();

            const $button = $('#donadosu-send-deactivation');
            const selected = $("input[name='reason']:checked");
            const reason = selected.val();
            const reasonDetails = selected
                .siblings('.donadosu-deactivation-Modal-fieldHidden')
                .find('textarea')
                .val();

            if (!reason) {
                window.alert('Please select a reason before deactivating.');
                return;
            }

            showLoading($modal);
            $button.prop('disabled', true).css({ cursor: 'not-allowed', opacity: '0.6' });

            $.post(ajaxurl, {
                action: 'donadosu_send_deactivation',
                nonce: feedbackNonce(),
                reason: reason,
                reason_details: reasonDetails || ''
            }).always(function () {
                window.location.replace($button.attr('href'));
            });
        });
    });

    class DonadosuDeactivationModal {
        constructor($elem) {
            this.$elem = $elem;
            // The overlay is a sibling of the modal, not a child — select it
            // globally so open()/close() can actually toggle the backdrop.
            this.$overlay = $('.donadosu-deactivation-Modal-overlay');
            this.$radio = $('input[name=reason]', $elem);
            this.$closer = $('.donadosu-deactivation-Modal-close, .donadosu-deactivation-Modal-cancel', $elem);
            this.$returnBtn = $('.donadosu-deactivation-Modal-return', $elem);
            this.$opener = this.findOpener();
            this.$question = $('.donadosu-deactivation-Modal-question', $elem);
            this.$button = $('.button-primary', $elem);
            this.$title = $('.donadosu-deactivation-Modal-header h2', $elem);
            this.$textFields = $('input[type=text], textarea', $elem);
            this.$hiddenReason = $('#deactivation-reason', $elem);
            this.$hiddenDetails = $('#deactivation-details', $elem);
            this.titleText = this.$title.text();
            this.bindEvents();
        }

        // Locate this plugin's Deactivate link. Prefer the reliable data-plugin
        // attribute (the plugin basename), then fall back to the folder slug.
        findOpener() {
            const cfg = window.donadosuFeedback || {};
            let $opener = $();
            if (cfg.pluginFile) {
                $opener = $('.plugins tr[data-plugin="' + cfg.pluginFile + '"] .deactivate');
            }
            if (!$opener.length && cfg.slug) {
                $opener = $('.plugins [data-slug="' + cfg.slug + '"] .deactivate');
            }
            return $opener;
        }

        bindEvents() {
            this.$opener.on('click', (e) => { e.preventDefault(); this.open(); });
            this.$closer.on('click', (e) => { e.preventDefault(); this.close(); });
            this.$elem.on('keyup', (event) => { if (event.keyCode === 27) { this.close(); } });
            this.$returnBtn.on('click', (e) => { e.preventDefault(); this.returnToQuestion(); });
            this.$radio.on('change', (e) => { this.change($(e.currentTarget)); });
            this.$textFields.on('keyup', (e) => {
                const value = $(e.currentTarget).val();
                this.$hiddenDetails.val(value);
                if (value !== '') {
                    this.$button.removeClass('deactivation-isDisabled').removeAttr('disabled');
                } else {
                    this.$button.addClass('deactivation-isDisabled').attr('disabled', true);
                }
            });
        }

        change($elem) {
            this.$hiddenReason.val($elem.val());
            this.$hiddenDetails.val('');
            this.$textFields.val('');
            $('.donadosu-deactivation-Modal-fieldHidden', this.$elem).removeClass('deactivation-isOpen');
            const $field = $elem.siblings('.donadosu-deactivation-Modal-fieldHidden');
            if ($field.length) {
                $field.addClass('deactivation-isOpen');
                $field.find('textarea').focus();
                this.$button.addClass('deactivation-isDisabled').attr('disabled', true);
            } else {
                this.$button.removeClass('deactivation-isDisabled').removeAttr('disabled');
            }
        }

        returnToQuestion() {
            $('.donadosu-deactivation-Modal-fieldHidden', this.$elem).removeClass('deactivation-isOpen');
            this.$question.addClass('deactivation-isOpen');
            this.$returnBtn.removeClass('deactivation-isOpen');
            this.$title.text(this.titleText);
            this.$hiddenReason.val('');
            this.$hiddenDetails.val('');
            this.$radio.prop('checked', false);
            this.$button.addClass('deactivation-isDisabled').attr('disabled', true);
        }

        open() {
            this.$elem.show();
            this.$overlay.show();
        }

        close() {
            this.returnToQuestion();
            this.$elem.hide();
            this.$overlay.hide();
        }
    }
})(jQuery);
