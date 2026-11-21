<?php
/**
 * Public directory: searchable, paginated member list.
 *
 * @package MMS_Membership
 *
 * @var string                        $mms_title       Directory heading.
 * @var string                        $mms_base_url    Directory page URL.
 * @var string                        $mms_search      Current search term.
 * @var array<int,array<string,mixed>> $mms_members    Member rows for this page.
 * @var int                           $mms_total       Number of matching members.
 * @var int                           $mms_page        Current page number.
 * @var int                           $mms_total_pages Total pages.
 * @var int                           $mms_from        First row number on this page.
 * @var int                           $mms_to          Last row number on this page.
 * @var string                        $mms_current_url Current list URL (search + page).
 * @var array<int,string>             $mms_page_links  Pagination links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( $mms_total > 0 ) {
	$mms_count_text = sprintf(
		/* translators: 1: first row, 2: last row, 3: total members */
		__( 'Showing %1$s–%2$s of %3$s members', 'mms-membership' ),
		number_format_i18n( $mms_from ),
		number_format_i18n( $mms_to ),
		number_format_i18n( $mms_total )
	);

	if ( '' !== $mms_search ) {
		$mms_count_text .= ' ' . sprintf(
			/* translators: %s: search term */
			__( 'for “%s”', 'mms-membership' ),
			$mms_search
		);
	}
} elseif ( '' !== $mms_search ) {
	$mms_count_text = __( 'No members match your search.', 'mms-membership' );
} else {
	$mms_count_text = __( 'No members are listed yet.', 'mms-membership' );
}
?>
<div class="mms-registration mms-directory">

	<div class="mms-dir-hero">
		<span class="mms-dir-eyebrow"><?php esc_html_e( 'MACM Members', 'mms-membership' ); ?></span>
		<h2><?php echo esc_html( $mms_title ); ?></h2>
		<p class="mms-dir-count"><?php echo esc_html( $mms_count_text ); ?></p>

		<form class="mms-dir-search" role="search" method="get" action="<?php echo esc_url( $mms_base_url ); ?>">
			<label class="mms-sr-only" for="mms-dir-q"><?php esc_html_e( 'Search members', 'mms-membership' ); ?></label>
			<div class="mms-dir-search-field">
				<span class="mms-dir-search-icon" aria-hidden="true">
					<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8.5" cy="8.5" r="5.5" stroke="currentColor" stroke-width="1.8"/><path d="M13 13l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
				</span>
				<input id="mms-dir-q" type="search" name="<?php echo esc_attr( MMS_Directory::SEARCH_ARG ); ?>" value="<?php echo esc_attr( $mms_search ); ?>" placeholder="<?php esc_attr_e( 'Search company name, SSM or membership no.', 'mms-membership' ); ?>">
			</div>
			<button type="submit" class="mms-button"><?php esc_html_e( 'Search', 'mms-membership' ); ?></button>
			<?php if ( '' !== $mms_search ) : ?>
				<a class="mms-dir-clear" href="<?php echo esc_url( $mms_base_url ); ?>"><?php esc_html_e( 'Clear', 'mms-membership' ); ?></a>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( empty( $mms_members ) ) : ?>
		<div class="mms-dir-empty">
			<h3><?php echo '' !== $mms_search ? esc_html__( 'No matching members', 'mms-membership' ) : esc_html__( 'Directory is empty', 'mms-membership' ); ?></h3>
			<p>
				<?php
				if ( '' !== $mms_search ) {
					/* translators: %s: search term */
					echo esc_html( sprintf( __( 'We could not find any member matching “%s”. Try a different name or number.', 'mms-membership' ), $mms_search ) );
				} else {
					esc_html_e( 'Member companies will appear here once their membership is approved.', 'mms-membership' );
				}
				?>
			</p>
			<?php if ( '' !== $mms_search ) : ?>
				<a class="mms-button mms-button-secondary" href="<?php echo esc_url( $mms_base_url ); ?>"><?php esc_html_e( 'Show all members', 'mms-membership' ); ?></a>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="mms-dir-grid">
			<?php foreach ( $mms_members as $mms_row ) : ?>
				<?php
				$mms_current     = MMS_Directory::is_current( $mms_row );
				$mms_validity    = MMS_Directory::validity( $mms_row );
				$mms_name        = '' !== (string) $mms_row['company_name'] ? $mms_row['company_name'] : __( 'Unnamed company', 'mms-membership' );
				$mms_detail_url  = add_query_arg( MMS_Directory::QUERY_ARG, (int) $mms_row['id'], $mms_current_url );
				?>
				<a class="mms-dir-tile" href="<?php echo esc_url( $mms_detail_url ); ?>">
					<span class="mms-dir-tile-top">
						<span class="mms-dir-avatar" aria-hidden="true"><?php echo esc_html( MMS_Directory::initial( $mms_name ) ); ?></span>
						<span class="mms-dir-pill <?php echo $mms_current ? 'is-active' : 'is-expired'; ?>">
							<?php echo $mms_current ? esc_html__( 'Active', 'mms-membership' ) : esc_html__( 'Expired', 'mms-membership' ); ?>
						</span>
					</span>
					<span class="mms-dir-tile-name"><?php echo esc_html( $mms_name ); ?></span>
					<span class="mms-dir-tile-meta">
						<span class="mms-dir-tile-label"><?php esc_html_e( 'Validity', 'mms-membership' ); ?></span>
						<span class="mms-dir-tile-value"><?php echo esc_html( '' !== $mms_validity ? $mms_validity : __( 'Dates not available', 'mms-membership' ) ); ?></span>
					</span>
					<span class="mms-dir-tile-cta"><?php esc_html_e( 'View details', 'mms-membership' ); ?> <span aria-hidden="true">→</span></span>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( ! empty( $mms_page_links ) ) : ?>
			<nav class="mms-dir-pagination" aria-label="<?php esc_attr_e( 'Directory pages', 'mms-membership' ); ?>">
				<?php foreach ( $mms_page_links as $mms_link ) : ?>
					<?php echo wp_kses_post( $mms_link ); ?>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

</div>
