/**
 * "Leave a review" admin notice actions.
 *
 * Reveals the notice a few seconds after the settings screen loads, then
 * handles Write Review / Done! / Remind me later / Hide clicks, records the
 * choice via AJAX, and fades the notice out.
 */
jQuery(function ($) {
    // Fade the notice in once the configured delay elapses. It is rendered
    // with the `--delayed` modifier (display: none), so swapping in
    // `--revealed` both shows it and plays the fade-in keyframe.
    const $delayed = $('.donadosu-review-notice--delayed');
    if ($delayed.length) {
        const revealDelay = (typeof donadosuReview !== 'undefined' && donadosuReview.reveal_delay)
            ? parseInt(donadosuReview.reveal_delay, 10)
            : 3000;

        window.setTimeout(function () {
            $delayed
                .removeClass('donadosu-review-notice--delayed')
                .addClass('donadosu-review-notice--revealed');
        }, revealDelay);
    }

    $('.donadosu-action-button').on('click', function (e) {
        e.preventDefault();
        $('.donadosu-review-notice').fadeOut();

        const action = $(this).data('action');
        const reviewUrl = (typeof donadosuReview !== 'undefined' && donadosuReview.review_url)
            ? donadosuReview.review_url
            : 'https://wordpress.org/support/plugin/donateocean-donation-suite/reviews/#new-post';

        if (action === 'reviewed') {
            window.open(reviewUrl, '_blank');
        }

        $.post(donadosuReview.ajax_url, {
            action: 'donadosu_handle_review_action',
            review_action: action,
            nonce: donadosuReview.nonce
        }, function (response) {
            if (!(response && response.success)) {
                window.console.error(response && response.data ? response.data : 'Unknown error');
            }
        });
    });
});
