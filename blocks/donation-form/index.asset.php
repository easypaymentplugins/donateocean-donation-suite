<?php
/**
 * Asset manifest for the donation form block editor script.
 *
 * Declares the WordPress script handles that blocks/donation-form/index.js
 * relies on at runtime (wp.blocks, wp.blockEditor, wp.components, wp.element,
 * wp.serverSideRender). Without this file WordPress would register the script
 * with no dependencies, leaving wp.serverSideRender undefined and breaking the
 * block edit() render with "Element type is invalid".
 *
 * @package Donation_Suite
 */

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-block-editor',
		'wp-components',
		'wp-element',
		'wp-server-side-render',
	),
	'version'      => '1.0.2',
);
