/**
 * Donation Suite Form — Gutenberg block editor script.
 *
 * Written as plain ES5 / no-build-system compatible code.
 * Uses globals provided by WordPress: wp.blocks, wp.blockEditor,
 * wp.components, wp.element, wp.serverSideRender.
 */
(function (blocks, blockEditor, components, element, serverSideRender) {
    'use strict';

    var el                = element.createElement;
    var Fragment          = element.Fragment;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody         = components.PanelBody;
    var PanelRow          = components.PanelRow;
    var TextControl       = components.TextControl;
    var SelectControl     = components.SelectControl;
    var ToggleControl     = components.ToggleControl;
    var RangeControl      = components.RangeControl;
    var ColorPicker       = components.ColorPicker;
    var Placeholder       = components.Placeholder;
    var Spinner           = components.Spinner;
    var ServerSideRender  = serverSideRender;

    blocks.registerBlockType('donadosu/donation-form', {

        edit: function (props) {
            var attrs      = props.attributes;
            var setAttr    = props.setAttributes;

            var showGoal   = attrs.goalAmount > 0;

            return el(
                Fragment,
                null,

                // ── Inspector sidebar controls ────────────────────────────────
                el(InspectorControls, { key: 'controls' },

                    el(PanelBody, { title: 'Campaign', initialOpen: true },
                        el(TextControl, {
                            label: 'Campaign / Fund name',
                            value: attrs.campaign,
                            onChange: function (v) { setAttr({ campaign: v }); },
                            help: 'Links this form to a named campaign for reporting and goal tracking.',
                        }),
                        el(TextControl, {
                            label: 'Purpose',
                            value: attrs.purpose,
                            onChange: function (v) { setAttr({ purpose: v }); },
                        })
                    ),

                    el(PanelBody, { title: 'Form options', initialOpen: true },
                        el(SelectControl, {
                            label: 'Display mode',
                            value: attrs.displayMode,
                            options: [
                                { label: 'Inline (default)', value: 'inline' },
                                { label: 'Modal (button trigger)', value: 'modal' },
                                { label: 'Widget (narrow)', value: 'widget' },
                                { label: 'Page (full width)', value: 'page' },
                            ],
                            onChange: function (v) { setAttr({ displayMode: v }); },
                        }),
                        el(TextControl, {
                            label: 'Currency override',
                            value: attrs.currency,
                            placeholder: 'e.g. USD (leave blank for default)',
                            onChange: function (v) { setAttr({ currency: v.toUpperCase() }); },
                            maxLength: 3,
                        }),
                        el(ToggleControl, {
                            label: 'Show donor details fields',
                            checked: attrs.donorFields,
                            onChange: function (v) { setAttr({ donorFields: v }); },
                        }),
                        el(TextControl, {
                            label: 'Button label',
                            value: attrs.buttonText,
                            onChange: function (v) { setAttr({ buttonText: v }); },
                        })
                    ),

                    el(PanelBody, { title: 'Accent colour', initialOpen: false },
                        el(PanelRow, null,
                            el('div', { style: { width: '100%' } },
                                el('p', { style: { marginBottom: '8px', fontSize: '12px' } },
                                    'Sets the button and accent colour on the donation form.'
                                ),
                                el(ColorPicker, {
                                    color: attrs.buttonColor || '#111111',
                                    onChange: function (v) {
                                        // ColorPicker may return an object or a hex string depending on WP version.
                                        var hex = (typeof v === 'object' && v !== null) ? (v.hex || '') : String(v);
                                        setAttr({ buttonColor: hex });
                                    },
                                    enableAlpha: false,
                                })
                            )
                        )
                    ),

                    el(PanelBody, { title: 'Goal / progress bar', initialOpen: false },
                        el(TextControl, {
                            label: 'Goal amount (0 to hide)',
                            type: 'number',
                            value: String(attrs.goalAmount || 0),
                            min: '0',
                            onChange: function (v) { setAttr({ goalAmount: parseFloat(v) || 0 }); },
                        }),
                        showGoal && el(TextControl, {
                            label: 'Current progress',
                            value: attrs.goalCurrent,
                            placeholder: 'Number or "auto" to calculate from donations',
                            onChange: function (v) { setAttr({ goalCurrent: v }); },
                            help: 'Enter "auto" to automatically sum completed donations for the campaign above.',
                        }),
                        showGoal && el(TextControl, {
                            label: 'Progress bar label',
                            value: attrs.goalLabel,
                            onChange: function (v) { setAttr({ goalLabel: v }); },
                        })
                    ),

                    el(PanelBody, { title: 'Recurring / frequency', initialOpen: false },
                        el(SelectControl, {
                            label: 'Donation mode',
                            value: attrs.donationMode,
                            options: [
                                { label: 'One-time only', value: 'one_time' },
                                { label: 'Monthly only', value: 'monthly' },
                                { label: 'Annual only', value: 'annual' },
                                { label: 'Donor chooses (default)', value: 'both' },
                            ],
                            onChange: function (v) { setAttr({ donationMode: v }); },
                            help: 'Requires "Enable recurring donations" to be on in plugin settings.',
                        })
                    ),

                    el(PanelBody, { title: 'Preset amounts', initialOpen: false },
                        el(TextControl, {
                            label: 'Custom preset amounts',
                            value: attrs.amounts,
                            placeholder: 'e.g. 10,25,50,100 (leave blank for default)',
                            onChange: function (v) { setAttr({ amounts: v }); },
                            help: 'Comma-separated list of preset donation amounts for this block.',
                        }),
                        el(TextControl, {
                            label: 'PayPal locale override',
                            value: attrs.locale,
                            placeholder: 'e.g. en_US, fr_FR (leave blank for auto)',
                            onChange: function (v) { setAttr({ locale: v }); },
                        })
                    ),

                    el(PanelBody, { title: 'Campaign schedule', initialOpen: false },
                        el(TextControl, {
                            label: 'Campaign start date',
                            value: attrs.campaignStart,
                            placeholder: 'YYYY-MM-DD',
                            onChange: function (v) { setAttr({ campaignStart: v }); },
                            help: 'Form is hidden with a "opens on" message before this date.',
                        }),
                        el(TextControl, {
                            label: 'Campaign end date',
                            value: attrs.campaignEnd,
                            placeholder: 'YYYY-MM-DD',
                            onChange: function (v) { setAttr({ campaignEnd: v }); },
                            help: 'Form is hidden with a "campaign ended" message after this date.',
                        }),
                        el(ToggleControl, {
                            label: 'Close form when goal is reached',
                            checked: attrs.goalClose,
                            onChange: function (v) { setAttr({ goalClose: v }); },
                            help: 'Requires a Goal amount set above.',
                        })
                    ),

                    el(PanelBody, { title: 'After payment', initialOpen: false },
                        el(TextControl, {
                            label: 'Thank-you page URL',
                            value: attrs.thankYouUrl,
                            placeholder: 'https://example.com/thank-you',
                            onChange: function (v) { setAttr({ thankYouUrl: v }); },
                            type: 'url',
                        }),
                        el(ToggleControl, {
                            label: 'Redirect to thank-you URL on success',
                            checked: attrs.redirectOnSuccess,
                            onChange: function (v) { setAttr({ redirectOnSuccess: v }); },
                        })
                    )
                ),

                // ── Editor canvas preview ─────────────────────────────────────
                el(ServerSideRender, {
                    key:    'preview',
                    block:  'donadosu/donation-form',
                    attributes: attrs,
                })
            );
        },

        // Fully server-side rendered — no client-side save needed.
        save: function () { return null; },
    });

})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.serverSideRender);
