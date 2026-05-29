<?php
/**
 * Modern card-style pure-PHP PDF receipt generator.
 *
 * Generates a clean, modern A4 PDF receipt matching the Donation Suite email
 * template design: white card on light gray background, subtle table rows,
 * warm neutral palette — all with zero external dependencies.
 *
 * @package    Donation_Suite
 * @subpackage Receipt
 * @since      1.0.0
 * @version    4.0.0
 */

namespace DonationSuite\Receipt;

use DonationSuite\Core\AddressFormatter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PdfReceiptGenerator
 *
 * Constructs a modern card-style PDF 1.4 document for donation receipts.
 *
 * @since 1.0.0
 */
class PdfReceiptGenerator {

	// ─── Page Geometry ──────────────────────────────────────────────────

	private const PAGE_W    = 595;
	private const PAGE_H    = 842;
	private const MARGIN_L  = 72;
	private const MARGIN_R  = 72;
	private const CONTENT_W = self::PAGE_W - self::MARGIN_L - self::MARGIN_R;

	// ─── Card Geometry ──────────────────────────────────────────────────

	private const CARD_X       = 48;
	private const CARD_W       = self::PAGE_W - ( self::CARD_X * 2 );
	private const CARD_PAD     = 32;
	private const CARD_INNER_X = self::CARD_X + self::CARD_PAD;
	private const CARD_INNER_W = self::CARD_W - ( self::CARD_PAD * 2 );

	// ─── Design Tokens (matching email template palette) ────────────────

	/** Page background (#f4f4f5 — zinc-100). */
	private const C_PAGE_BG = '0.957 0.957 0.961';

	/** Card background — white. */
	private const C_WHITE = '1.000 1.000 1.000';

	/** Primary text (#111111). */
	private const C_PRIMARY = '0.067 0.067 0.067';

	/** Secondary text / labels (#525252 — neutral-600). */
	private const C_SECONDARY = '0.322 0.322 0.322';

	/** Tertiary text (#71717a — zinc-500). */
	private const C_TERTIARY = '0.443 0.443 0.478';

	/** Card border (#e4e4e7 — zinc-200). */
	private const C_BORDER = '0.894 0.894 0.906';

	/** Row separator (#f4f4f5 — same as page bg). */
	private const C_ROW_LINE = '0.957 0.957 0.961';

	/** Info box background (#f9fafb — gray-50). */
	private const C_INFO_BG = '0.976 0.980 0.984';

	/** Amount highlight — bold primary. */
	private const C_AMOUNT = '0.067 0.067 0.067';

	// ─── Instance State ─────────────────────────────────────────────────

	private string $stream = '';
	private float  $y      = 0.0;

	/**
	 * Generate a PDF receipt and return the raw bytes.
	 *
	 * @param array $data Receipt data keyed by field name.
	 * @return string Raw PDF binary content.
	 */
	public function generate( array $data ): string {
		$this->stream = '';
		$this->y      = 0.0;

		$this->build_stream( $data );

		return $this->build_pdf();
	}

	// ─── Content Builder ────────────────────────────────────────────────

	private function build_stream( array $d ): void {

		$org_name      = (string) ( $d['org_name']      ?? '' );
		$receipt_no    = (string) ( $d['receipt_no']     ?? '' );
		$donation_date = (string) ( $d['donation_date']  ?? '' );
		$currency      = (string) ( $d['currency']       ?? '' );
		$amount        = number_format( (float) ( $d['amount'] ?? 0 ), 2 );
		$amount_str    = strtoupper( $currency ) . ' ' . $amount;

		// ── Page background ─────────────────────────────────────────────
		$this->rect( 0, 0, self::PAGE_W, self::PAGE_H, self::C_PAGE_BG );

		// ── White card ──────────────────────────────────────────────────
		$card_top    = self::PAGE_H - 40;
		$card_bottom = 60;
		$card_h      = $card_top - $card_bottom;
		$this->rect( self::CARD_X, $card_bottom, self::CARD_W, $card_h, self::C_WHITE );

		// Card border (four sides).
		$this->line( self::CARD_X, $card_top, self::CARD_X + self::CARD_W, $card_top, self::C_BORDER, 0.5 );
		$this->line( self::CARD_X, $card_bottom, self::CARD_X + self::CARD_W, $card_bottom, self::C_BORDER, 0.5 );
		$this->line( self::CARD_X, $card_bottom, self::CARD_X, $card_top, self::C_BORDER, 0.5 );
		$this->line( self::CARD_X + self::CARD_W, $card_bottom, self::CARD_X + self::CARD_W, $card_top, self::C_BORDER, 0.5 );

		$this->y = $card_top - self::CARD_PAD;

		// ── Title ───────────────────────────────────────────────────────
		$title      = '' !== $org_name
			? $org_name . "\n" . __( 'Donation Receipt', 'donateocean-donation-suite' )
			: __( 'Donation Receipt', 'donateocean-donation-suite' );
		$title_size = 18;
		$this->put_text( self::CARD_INNER_X, $this->y, $title, $title_size, true, self::C_PRIMARY );
		$title_max  = (int) floor( self::CARD_INNER_W / ( $title_size * 0.52 ) );
		$title_lines = count( $this->wrap( $title, $title_max ) );
		$this->y -= $title_lines * ( $title_size + 4 ) + 2;
		$this->put_text( self::CARD_INNER_X, $this->y, __( 'Thank you for your generous donation.', 'donateocean-donation-suite' ), 10, false, self::C_SECONDARY );
		$this->y -= 20;

		// ── Donation details table ──────────────────────────────────────
		$this->put_text( self::CARD_INNER_X, $this->y, __( 'Donation details', 'donateocean-donation-suite' ), 12, true, self::C_PRIMARY );
		$this->y -= 20;

		$frequency = (string) ( $d['frequency'] ?? 'one_time' );
		$freq_map  = array(
			'monthly'  => __( 'Monthly recurring', 'donateocean-donation-suite' ),
			'annual'   => __( 'Annual recurring', 'donateocean-donation-suite' ),
			'one_time' => __( 'One-time donation', 'donateocean-donation-suite' ),
		);
		$freq_label = $freq_map[ $frequency ] ?? __( 'One-time donation', 'donateocean-donation-suite' );

		$campaign    = (string) ( $d['campaign']          ?? '' );
		$purpose     = (string) ( $d['purpose']           ?? '' );
		$offline_ref = (string) ( $d['offline_reference'] ?? '' );

		$this->y = $this->table_row( $this->y, __( 'Receipt #', 'donateocean-donation-suite' ), $receipt_no, false, false );
		$this->y = $this->table_row( $this->y, __( 'Donation date', 'donateocean-donation-suite' ), $donation_date ? $donation_date . ' UTC' : '', false, false );
		$this->y = $this->table_row( $this->y, __( 'Amount paid', 'donateocean-donation-suite' ), $amount_str, true, false );
		$this->y = $this->table_row( $this->y, __( 'Donation type', 'donateocean-donation-suite' ), $freq_label, false, false );
		if ( $campaign ) {
			$this->y = $this->table_row( $this->y, __( 'Campaign / Fund', 'donateocean-donation-suite' ), $campaign, false, false );
		}
		if ( $purpose ) {
			$this->y = $this->table_row( $this->y, __( 'Purpose', 'donateocean-donation-suite' ), $purpose, false, false );
		}
		if ( $offline_ref ) {
			$this->y = $this->table_row( $this->y, __( 'Reference #', 'donateocean-donation-suite' ), $offline_ref, false, false );
		}
		$payment_source = ucfirst( (string) ( $d['payment_source'] ?? '' ) ) ?: 'PayPal';
		$this->y = $this->table_row( $this->y, __( 'Payment method', 'donateocean-donation-suite' ), $payment_source, false, false );

		$this->y -= 10;

		// ── Organization details table (only when configured) ───────────
		$reg_id        = (string) ( $d['reg_id']        ?? '' );
		$org_address   = (string) ( $d['org_address']   ?? '' );
		$contact_email = (string) ( $d['contact_email'] ?? '' );

		$has_org_details = '' !== $org_name || '' !== $reg_id || '' !== $org_address || '' !== $contact_email;

		if ( $has_org_details ) {
			$this->card_divider();
			$this->y -= 18;

			$this->put_text( self::CARD_INNER_X, $this->y, __( 'Organization details', 'donateocean-donation-suite' ), 12, true, self::C_PRIMARY );
			$this->y -= 20;

			if ( '' !== $org_name ) {
				$this->y = $this->table_row( $this->y, __( 'Name', 'donateocean-donation-suite' ), $org_name, false, false );
			}
			if ( '' !== $reg_id ) {
				$this->y = $this->table_row( $this->y, __( 'Registration / Tax ID', 'donateocean-donation-suite' ), $reg_id, false, false );
			}
			if ( '' !== $org_address ) {
				$formatted_org_addr = AddressFormatter::format_org( $org_address );
				if ( '' !== $formatted_org_addr ) {
					$this->y = $this->table_row( $this->y, __( 'Address', 'donateocean-donation-suite' ), $formatted_org_addr, false, false );
				}
			}
			if ( '' !== $contact_email ) {
				$this->y = $this->table_row( $this->y, __( 'Contact', 'donateocean-donation-suite' ), $contact_email, false, false );
			}

			$this->y -= 10;
		}

		// ── Donor Details ───────────────────────────────────────────────
		$donor_name    = (string) ( $d['donor_name']    ?? '' );
		$donor_email   = (string) ( $d['donor_email']   ?? '' );
		$donor_company = (string) ( $d['donor_company'] ?? '' );
		$donor_address = (string) ( $d['donor_address'] ?? '' );
		$donor_city    = (string) ( $d['donor_city']    ?? '' );
		$donor_postal  = (string) ( $d['donor_postal']  ?? '' );

		if ( $donor_name || $donor_email ) {
			$this->card_divider();
			$this->y -= 18;

			$this->put_text( self::CARD_INNER_X, $this->y, __( 'Donor details', 'donateocean-donation-suite' ), 12, true, self::C_PRIMARY );
			$this->y -= 20;

			if ( $donor_name ) {
				$this->y = $this->table_row( $this->y, __( 'Name', 'donateocean-donation-suite' ), $donor_name, false, false );
			}
			if ( $donor_email ) {
				$this->y = $this->table_row( $this->y, __( 'Email', 'donateocean-donation-suite' ), $donor_email, false, false );
			}
			if ( $donor_company ) {
				$this->y = $this->table_row( $this->y, __( 'Organization', 'donateocean-donation-suite' ), $donor_company, false, false );
			}

			$formatted_donor_addr = AddressFormatter::format_donor( $donor_address, $donor_city, $donor_postal );
			if ( '' !== $formatted_donor_addr ) {
				$this->y = $this->table_row( $this->y, __( 'Address', 'donateocean-donation-suite' ), $formatted_donor_addr, false, false );
			}

			$this->y -= 10;
		}

		// ── Tax Statement (info box) ────────────────────────────────────
		$tax_statement = (string) ( $d['tax_statement'] ?? '' );
		if ( $tax_statement ) {
			$this->y -= 4;
			$lines     = $this->wrap( $tax_statement, 72 );
			$box_h     = ( count( $lines ) * 14 ) + 20;
			$box_x     = self::CARD_INNER_X;
			$box_w     = self::CARD_INNER_W;

			$this->rect( $box_x, $this->y - $box_h + 10, $box_w, $box_h, self::C_INFO_BG );

			$text_y = $this->y;
			foreach ( $lines as $line ) {
				$this->put_text( $box_x, $text_y, $line, 9, false, self::C_SECONDARY );
				$text_y -= 14;
			}
			$this->y -= $box_h + 6;
		}

		// ── Footer (below card) ─────────────────────────────────────────
		$footer_y    = 44;
		$footer_text = '';
		if ( '' !== $contact_email ) {
			$footer_text = 'Questions? Contact ' . $contact_email;
		} elseif ( '' !== $org_name ) {
			$footer_text = 'Questions? Contact ' . $org_name;
		}

		if ( '' !== $footer_text ) {
			$this->put_text(
				self::CARD_X,
				$footer_y,
				$footer_text,
				7,
				false,
				self::C_TERTIARY
			);
		}

		$page_text = 'Page 1 of 1';
		$page_w    = strlen( $page_text ) * 3.6;
		$this->put_text(
			self::PAGE_W - self::CARD_X - $page_w,
			$footer_y,
			$page_text,
			7,
			false,
			self::C_TERTIARY
		);
	}

	// ─── Table Row ─────────────────────────────────────────────────────

	/**
	 * Render a table row with label on left and value on right.
	 * Draws a subtle bottom border unless $show_border is false.
	 */
	private function table_row( float $y, string $label, string $value, bool $bold_value = false, bool $show_border = true ): float {
		$label_x   = self::CARD_INNER_X;
		$value_x   = self::CARD_INNER_X + ( self::CARD_INNER_W * 0.45 );
		$value_w   = self::CARD_INNER_W * 0.55;
		$font_size = 9;

		$this->put_text( $label_x, $y, $label, $font_size, false, self::C_SECONDARY );
		$this->put_text( $value_x, $y, $value, $font_size, $bold_value, self::C_PRIMARY, $value_w );

		// Calculate actual text height to support multi-line values.
		$max_chars  = (int) floor( $value_w / ( $font_size * 0.52 ) );
		$num_lines  = max( 1, count( $this->wrap( $value, $max_chars ) ) );
		$text_h     = $num_lines * ( $font_size + 4 );
		$row_bottom = $y - max( 12, $text_h );

		if ( $show_border ) {
			$this->line( self::CARD_INNER_X, $row_bottom, self::CARD_INNER_X + self::CARD_INNER_W, $row_bottom, self::C_ROW_LINE, 0.5 );
		}

		return $row_bottom - 6;
	}

	// ─── Drawing Primitives ─────────────────────────────────────────────

	/**
	 * Draw a full-width card divider.
	 */
	private function card_divider(): void {
		$this->line(
			self::CARD_INNER_X,
			$this->y,
			self::CARD_INNER_X + self::CARD_INNER_W,
			$this->y,
			self::C_BORDER,
			0.5
		);
	}

	/**
	 * Draw a filled rectangle.
	 */
	private function rect( float $x, float $y, float $w, float $h, string $rgb ): void {
		$this->stream .= sprintf(
			"q %s rg %s %s %s %s re f Q\n",
			$rgb,
			$this->fmt( $x ),
			$this->fmt( $y ),
			$this->fmt( $w ),
			$this->fmt( $h )
		);
	}

	/**
	 * Draw a line between two points.
	 */
	private function line( float $x1, float $y1, float $x2, float $y2, string $rgb, float $width = 0.5 ): void {
		$this->stream .= sprintf(
			"q %s w %s RG %s %s m %s %s l S Q\n",
			$this->fmt( $width ),
			$rgb,
			$this->fmt( $x1 ),
			$this->fmt( $y1 ),
			$this->fmt( $x2 ),
			$this->fmt( $y2 )
		);
	}

	// ─── Text Rendering ─────────────────────────────────────────────────

	/**
	 * Render text at given coordinates.
	 */
	private function put_text( float $x, float $y, string $text, int $size, bool $bold = false, string $rgb = '0 0 0', float $max_width = 0 ): void {
		$font      = $bold ? 'F2' : 'F1';
		$wrap_w    = $max_width > 0 ? $max_width : self::CARD_INNER_W;
		$max_chars = (int) floor( $wrap_w / ( $size * 0.52 ) );

		foreach ( $this->wrap( $text, $max_chars ) as $i => $line ) {
			$line_y = $y - ( $i * ( $size + 4 ) );
			$this->stream .= sprintf(
				"BT /%s %d Tf %s rg %s %s Td (%s) Tj ET\n",
				$font,
				$size,
				$rgb,
				$this->fmt( $x ),
				$this->fmt( $line_y ),
				$this->esc( $line )
			);
		}
	}

	// ─── Utility Methods ────────────────────────────────────────────────

	private function fmt( float $v ): string {
		return number_format( $v, 3, '.', '' );
	}

	private function esc( string $s ): string {
		$s = mb_convert_encoding( $s, 'ISO-8859-1', 'UTF-8' );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $s );
	}

	private function wrap( string $text, int $max_chars = 80 ): array {
		$text       = trim( $text );
		$paragraphs = preg_split( '/\r?\n/', $text );
		$lines      = array();

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = preg_replace( '/[ \t]+/', ' ', trim( $paragraph ) ) ?? $paragraph;
			if ( '' === $paragraph ) {
				continue;
			}

			while ( mb_strlen( $paragraph ) > $max_chars ) {
				$pos = mb_strrpos( mb_substr( $paragraph, 0, $max_chars ), ' ' );
				if ( false === $pos ) {
					$pos = $max_chars;
				}
				$lines[]   = mb_substr( $paragraph, 0, $pos );
				$paragraph = ltrim( mb_substr( $paragraph, $pos ) );
			}

			if ( '' !== $paragraph ) {
				$lines[] = $paragraph;
			}
		}

		return $lines;
	}

	// ─── PDF Structure ──────────────────────────────────────────────────

	private function build_pdf(): string {
		$stream_content = $this->stream;
		$stream_len     = strlen( $stream_content );

		$body    = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();

		// Object 1 — Catalog.
		$offsets[1] = strlen( $body );
		$body      .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

		// Object 2 — Pages.
		$offsets[2] = strlen( $body );
		$body      .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

		// Object 3 — Page.
		$offsets[3] = strlen( $body );
		$body      .= sprintf(
			"3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d]"
			. " /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >>"
			. " /Contents 6 0 R >>\nendobj\n",
			self::PAGE_W,
			self::PAGE_H
		);

		// Object 4 — Font: Helvetica.
		$offsets[4] = strlen( $body );
		$body      .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

		// Object 5 — Font: Helvetica-Bold.
		$offsets[5] = strlen( $body );
		$body      .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

		// Object 6 — Content stream.
		$offsets[6] = strlen( $body );
		$body      .= sprintf(
			"6 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n",
			$stream_len + 1,
			$stream_content
		);

		// Cross-reference table.
		$xref_start = strlen( $body );
		$body      .= "xref\n0 7\n";
		$body      .= "0000000000 65535 f \n";

		foreach ( array( 1, 2, 3, 4, 5, 6 ) as $n ) {
			$body .= sprintf( "%010d 00000 n \n", $offsets[ $n ] );
		}

		$body .= "trailer\n<< /Size 7 /Root 1 0 R >>\n";
		$body .= "startxref\n{$xref_start}\n%%EOF\n";

		return $body;
	}
}
