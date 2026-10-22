<?php
/**
 * File uploads: checks, private storage, downloads, deletion, privacy.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\EntriesTestCase;
use PegworkForms\Cleanup;
use PegworkForms\EntriesAdmin;
use PegworkForms\Entries;
use PegworkForms\Export;
use PegworkForms\Files;
use PegworkForms\Notifications;
use PegworkForms\Privacy;
use PegworkForms\Renderer;
use PegworkForms\Submission;
use PegworkForms\Uploads;

/**
 * Form fr_upload: `doc` (file, required, accept pdf/png/txt, max_size_mb 0.01), `email`, `note`.
 * Uploads land in a temporary uploads folder (FormsTestCase).
 */
class FormsUploadsTest extends EntriesTestCase {

	/**
	 * Visitors submit; the entries screen renders on its admin screen.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	/**
	 * Valid fr_upload values.
	 *
	 * @param array $overrides Changes.
	 */
	private function values( $overrides = array() ) {
		return array_merge(
			array(
				'email' => 'Ada@Example.org',
				'note'  => 'My report',
			),
			$overrides
		);
	}

	/**
	 * Submits fr_upload with files.
	 *
	 * @param array $files  Uploads by key.
	 * @param array $values Values.
	 * @param array $control Control overrides.
	 * @return array Outcome.
	 */
	private function send( $files, $values = null, $control = array() ) {
		return Submission::process( pegwork_registry()->form( 'fr_upload' ), $values ?? $this->values(), $this->control( 'fr_upload', 30, $control ), self::NOW, 0, $files );
	}

	/**
	 * A stored entry with report.pdf, as an administrator would find it.
	 *
	 * @return array{0: int, 1: array} Entry ID and the file value.
	 */
	private function stored_entry() {
		$result = $this->send( array( 'doc' => $this->upload( 'report.pdf', self::pdf_bytes() ) ) );
		$this->assertSame( 'sent', $result['outcome'] );
		return array( $result['entry_id'], Entries::get( $result['entry_id'] )['values']['doc'] );
	}

	/**
	 * Renders the entries screen with query arguments.
	 *
	 * @param array $query Query arguments.
	 */
	private function admin_screen( $query ) {
		set_current_screen( 'pegwork_page_' . Notifications::ENTRIES_PAGE );
		$_GET     = $query;
		$_REQUEST = $query;
		ob_start();
		EntriesAdmin::render();
		$html = ob_get_clean();
		set_current_screen( 'front' );
		return $html;
	}

	/**
	 * Only a form with a file item posts multipart; the input lists accepted types and states them as help.
	 */
	public function test_markup() {
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_upload"} /-->' );
		$this->assertStringContainsString( 'method="post" action="http://example.org/contact/" enctype="multipart/form-data" novalidate data-pegwork-config="', $html );
		$this->assertStringContainsString( '<input class="pegwork-form__file" type="file" id="pegwork-fr_upload-doc" name="pegwork[doc]" accept=".pdf,.png,.txt" aria-required="true" aria-describedby="pegwork-fr_upload-doc-help">', $html );
		$this->assertStringContainsString( '<p class="pegwork-form__help" id="pegwork-fr_upload-doc-help">PDF, PNG or TXT, up to 10 KB.</p>', $html );
		$this->assertStringContainsString( '<label class="pegwork-form__label" for="pegwork-fr_upload-doc">Document <span class="pegwork-form__required">(required)</span></label>', $html );

		$this->assertStringNotContainsString( 'enctype', do_blocks( '<!-- wp:pegwork/form {"form":"fr_contact"} /-->' ) );
	}

	/**
	 * A valid upload is stored under uploads/pegwork-forms/<32 hex>/ with guard files, mailed and
	 * emitted by name only.
	 */
	public function test_upload_is_stored_privately() {
		[ $id, $value ] = $this->stored_entry();

		$this->assertSame( array( 'path', 'name', 'size', 'mime' ), array_keys( $value ) );
		$this->assertMatchesRegularExpression( '#^pegwork-forms/[0-9a-f]{32}/report\.pdf$#', $value['path'] );
		$this->assertSame( 'report.pdf', $value['name'] );
		$this->assertSame( strlen( self::pdf_bytes() ), $value['size'] );
		$this->assertSame( 'application/pdf', $value['mime'] );

		$file = $this->uploads_dir . '/' . $value['path'];
		$this->assertFileExists( $file );
		$this->assertSame( self::pdf_bytes(), file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test read.
		$this->assertSame( realpath( $file ), Uploads::absolute( $value ) );
		$this->assertFileExists( dirname( $file ) . '/index.php' );
		$this->assertFileExists( $this->uploads_dir . '/pegwork-forms/index.php' );
		$htaccess = file_get_contents( $this->uploads_dir . '/pegwork-forms/.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test read.
		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );

		$mail = (array) $this->sent_mail()[0];
		$this->assertSame( 'Document from Ada@Example.org: report.pdf', $mail['subject'] );
		$this->assertStringContainsString( 'Document: report.pdf', $mail['body'] );
		$this->assertStringNotContainsString( 'pegwork-forms', $mail['body'] );

		$event = $this->events[ count( $this->events ) - 1 ];
		$this->assertSame( 'form.submitted', $event[0] );
		$this->assertSame(
			array(
				'name' => 'report.pdf',
				'size' => strlen( self::pdf_bytes() ),
				'mime' => 'application/pdf',
			),
			$event[1]['values']['doc'],
			'Events carry name, size and type, never the path.'
		);
		$this->assertSame( $id, $event[1]['entry_id'] );
	}

	/**
	 * Wrong type, spoofed content, mismatched content, double extensions, denylisted extensions, too
	 * large and empty files are refused, and nothing is stored.
	 */
	public function test_rejection_list() {
		$php   = "<?php echo 'owned'; ?>\n";
		$cases = array(
			'wrong type'             => array( 'notes.docx', 'Plain words', 'must be PDF, PNG or TXT, up to 10 KB.' ),
			'spoofed content'        => array( 'report.pdf', $php, 'does not match its file type' ),
			'png named pdf'          => array( 'report.pdf', self::png_bytes(), 'does not match its file type' ),
			'double extension'       => array( 'photo.php.png', self::png_bytes(), 'must be PDF, PNG or TXT' ),
			'double extension phtml' => array( 'photo.phtml.txt', 'text', 'must be PDF, PNG or TXT' ),
			'php'                    => array( 'shell.php', $php, 'must be PDF, PNG or TXT' ),
			'phtml'                  => array( 'shell.phtml', $php, 'must be PDF, PNG or TXT' ),
			'phar'                   => array( 'shell.phar', $php, 'must be PDF, PNG or TXT' ),
			'svg'                    => array( 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'must be PDF, PNG or TXT' ),
			'html'                   => array( 'page.html', '<html><script>alert(1)</script></html>', 'must be PDF, PNG or TXT' ),
			'no extension'           => array( 'README', 'text', 'must be PDF, PNG or TXT' ),
			'too large'              => array( 'big.txt', str_repeat( 'a', 20 * 1024 ), 'is too large. Choose a file up to 10 KB.' ),
			'empty'                  => array( 'empty.txt', '', 'is empty' ),
		);
		foreach ( $cases as $case => [ $name, $content, $message ] ) {
			$result = $this->send( array( 'doc' => $this->upload( $name, $content ) ), $this->values( array( 'note' => $case ) ) );
			$this->assertSame( 'invalid', $result['outcome'], $case );
			$this->assertStringContainsString( $message, $result['errors']['doc'] ?? '', $case );
			$this->assertStringStartsWith( 'The file for Document', $result['errors']['doc'], $case );
		}
		$this->assertSame( 0, $this->count_entries() );
		$this->assertDirectoryDoesNotExist( $this->uploads_dir . '/pegwork-forms', 'Nothing was written.' );

		// The upload error PHP reports for files over upload_max_filesize.
		$result = $this->send(
			array(
				'doc' => array(
					'name'     => 'huge.pdf',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_INI_SIZE,
					'size'     => 0,
				),
			)
		);
		$this->assertStringContainsString( 'is too large', $result['errors']['doc'] );
	}

	/**
	 * The runtime denylist holds even for a definition that slipped past the registry validator.
	 */
	public function test_runtime_denylist() {
		$item = array(
			'key'    => 'x',
			'type'   => 'file',
			'label'  => 'X',
			'accept' => array( 'php', 'phtml', 'svg', 'html', 'js', 'exe', 'pdf' ),
		);
		$this->assertSame( array( 'pdf' => 'application/pdf' ), Uploads::mimes( $item ) );
		$this->assertSame( array( 'pdf' ), Uploads::extensions( $item ) );
		$this->assertSame( array( 'jpg|jpeg' => 'image/jpeg' ), Uploads::mimes( array( 'accept' => array( 'jpeg', 'jpg' ) ) ) );
	}

	/**
	 * A required file must be chosen; when another field has a problem the file is discarded and the
	 * field asks to choose it again.
	 */
	public function test_required_and_choose_again() {
		$result = $this->send( array() );
		$this->assertSame( 'invalid', $result['outcome'] );
		$this->assertSame( 'Document is required.', $result['errors']['doc'] );

		$result = $this->send( array( 'doc' => $this->upload( 'report.pdf', self::pdf_bytes() ) ), $this->values( array( 'note' => '' ) ) );
		$this->assertSame( 'invalid', $result['outcome'] );
		$this->assertSame( array( 'note' ), array_keys( $result['errors'] ) );
		$this->assertSame( array( 'doc' => 'report.pdf' ), $result['files'] );
		$this->assertSame( 0, $this->count_entries() );
		$this->assertDirectoryDoesNotExist( $this->uploads_dir . '/pegwork-forms' );

		$state = new \ReflectionProperty( Submission::class, 'state' );
		$state->setValue( null, array( 'fr_upload' => $result ) );
		Renderer::reset();
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_upload"} /-->' );
		$this->assertStringContainsString( 'aria-describedby="pegwork-fr_upload-doc-help pegwork-fr_upload-doc-again"', $html );
		$this->assertStringContainsString( '<p class="pegwork-form__help pegwork-form__again" id="pegwork-fr_upload-doc-again"><strong>Choose the file again. &quot;report.pdf&quot; was not kept.</strong></p>', $html );
		$this->assertStringContainsString( 'value="ada@example.org"', strtolower( $html ) );
	}

	/**
	 * Only files PHP received for this request are taken from $_FILES; "no file" is dropped.
	 */
	public function test_from_request_takes_only_real_uploads() {
		$fake              = $this->upload( 'report.pdf', self::pdf_bytes() );
		$_FILES['pegwork'] = array(
			'name'     => array(
				'doc'   => 'report.pdf',
				'note'  => 'x.txt',
				'email' => 'y.txt',
			),
			'tmp_name' => array(
				'doc'   => $fake['tmp_name'],
				'note'  => $fake['tmp_name'],
				'email' => '',
			),
			'error'    => array(
				'doc'   => UPLOAD_ERR_OK,
				'note'  => UPLOAD_ERR_OK,
				'email' => UPLOAD_ERR_NO_FILE,
			),
			'size'     => array(
				'doc'   => $fake['size'],
				'note'  => $fake['size'],
				'email' => 0,
			),
		);
		$form              = pegwork_registry()->form( 'fr_upload' );
		$this->assertSame( array(), Uploads::from_request( $form ), 'A file already on the server is not an upload; only file items count.' );

		$_FILES['pegwork']['error']['doc'] = UPLOAD_ERR_INI_SIZE;
		$this->assertSame( array( 'doc' ), array_keys( Uploads::from_request( $form ) ), 'Upload errors are kept so they can be reported.' );
	}

	/**
	 * Downloads need the forms capability and a nonce for that entry and field.
	 */
	public function test_download_needs_capability_and_nonce() {
		[ $id, $value ] = $this->stored_entry();
		$this->assertFalse( has_action( 'admin_post_nopriv_' . Files::ACTION ), 'Logged-out visitors get admin-post.php\'s 400.' );
		$this->assertNotFalse( has_action( 'admin_post_' . Files::ACTION, array( Files::class, 'handle' ) ) );

		$this->login_as( 'administrator' );
		$url = Files::url( $id, 'doc' );
		$this->assertStringStartsWith( 'http://example.org/wp-admin/admin-post.php?action=pegwork_forms_file&entry=' . $id . '&field=doc&_wpnonce=', $url );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$file = Files::authorize( $query );
		$this->assertSame(
			array(
				'file' => Uploads::absolute( $value ),
				'name' => 'report.pdf',
				'mime' => 'application/pdf',
			),
			$file
		);

		$bad = array( '_wpnonce' => 'nope' ) + $query;
		$this->assertSame( 403, Files::authorize( $bad )->get_error_data()['status'], 'Bad nonce.' );
		$other = array( 'field' => 'note' ) + $query;
		$this->assertSame( 403, Files::authorize( $other )->get_error_data()['status'], 'A nonce is for one field.' );
		$_GET = $bad;
		$this->assertSame( 403, $this->die_code( array( Files::class, 'handle' ) ) );

		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			$this->login_as( $role );
			$query['_wpnonce'] = wp_create_nonce( Files::nonce_action( $id, 'doc' ) );
			$this->assertSame( 403, Files::authorize( $query )->get_error_data()['status'], $role );
			$_GET = $query;
			$this->assertSame( 403, $this->die_code( array( Files::class, 'handle' ) ), $role );
		}

		wp_set_current_user( 0 );
		$query['_wpnonce'] = wp_create_nonce( Files::nonce_action( $id, 'doc' ) );
		$this->assertSame( 403, Files::authorize( $query )->get_error_data()['status'], 'Anonymous.' );

		$this->login_as( 'administrator' );
		$query['_wpnonce'] = wp_create_nonce( Files::nonce_action( $id, 'doc' ) );
		wp_delete_file( Uploads::absolute( $value ) );
		$this->assertSame( 404, Files::authorize( $query )->get_error_data()['status'], 'Missing file.' );
	}

	/**
	 * Download headers name the file safely.
	 */
	public function test_disposition() {
		$this->assertSame( 'attachment; filename="report.pdf"; filename*=UTF-8\'\'report.pdf', Files::disposition( 'report.pdf' ) );
		$this->assertSame( 'attachment; filename="R_sum_x_.pdf"; filename*=UTF-8\'\'R%C3%A9sum%C3%A9%20%22x%22.pdf', Files::disposition( 'Résumé "x".pdf' ) );
	}

	/**
	 * Paths outside uploads/pegwork-forms are never resolved.
	 */
	public function test_paths_outside_storage_are_refused() {
		[ , $value ] = $this->stored_entry();
		$this->assertNotNull( Uploads::absolute( $value ) );
		foreach ( array( 'pegwork-forms/../../wp-config.php', '../pegwork-forms/' . substr( $value['path'], 14 ), '/etc/passwd', 'pegwork-forms/abc/report.pdf' ) as $path ) {
			$this->assertNull( Uploads::absolute( array( 'path' => $path ) + $value ), $path );
		}
		$this->assertNull( Uploads::absolute( 'pegwork-forms/x' ) );
	}

	/**
	 * Files go with their entry: admin delete, retention and the privacy eraser all use Entries::delete().
	 */
	public function test_files_are_deleted_with_their_entry() {
		[ $id, $value ] = $this->stored_entry();
		$file           = Uploads::absolute( $value );
		$dir            = dirname( $file );
		$this->assertSame( 1, Entries::delete( array( $id ) ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertFileExists( $this->uploads_dir . '/pegwork-forms/.htaccess', 'The guard stays.' );

		// Retention: a spam entry (held by Turnstile, file kept) older than 30 days.
		[ $id, $value ] = $this->stored_entry();
		Entries::delete( array() );
		\PegworkForms\EntryState::set(
			$id,
			array(
				'status' => 'spam',
			)
		);
		global $wpdb;
		$wpdb->update( \PegworkForms\Schema::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 40 * DAY_IN_SECONDS ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$file = Uploads::absolute( $value );
		$this->assertSame( 1, Cleanup::run( self::NOW ) );
		$this->assertFileDoesNotExist( $file );

		// Privacy eraser.
		[ , $value ] = $this->stored_entry();
		$file        = Uploads::absolute( $value );
		$this->assertTrue( Privacy::erase( 'ada@example.org' )['items_removed'] );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * The entry screen links the file by name to the private download; CSV shows the name only.
	 */
	public function test_entry_screen_and_csv() {
		[ $id ] = $this->stored_entry();
		$this->login_as( 'administrator' );

		$html = $this->admin_screen( array( 'entry' => (string) $id ) );
		$this->assertMatchesRegularExpression( '#<tr><th scope="row">Document</th><td><a href="http://example.org/wp-admin/admin-post\.php\?action=pegwork_forms_file&\#038;entry=' . $id . '&\#038;field=doc&\#038;_wpnonce=[0-9a-f]+" download style="text-decoration:underline">report\.pdf</a> <span class="description">\(\d+ B, application/pdf\)</span></td></tr>#', $html );
		$this->assertStringNotContainsString( 'pegwork-forms/', $html, 'No storage path on screen.' );

		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test stream.
		Export::write( $out, 'fr_upload', 'all' );
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test stream.
		$this->assertStringContainsString( ',report.pdf,', $csv );
		$this->assertStringNotContainsString( 'pegwork-forms', $csv );

		// Honeypot spam keeps the file's name, never the file.
		wp_set_current_user( 0 );
		$spam = $this->send( array( 'doc' => $this->upload( 'bot.pdf', self::pdf_bytes() ) ), $this->values( array( 'note' => 'Bot' ) ), array( 'url' => 'http://spam.example' ) );
		$this->assertSame( 'spam', $spam['outcome'] );
		$value = Entries::get( $spam['entry_id'] )['values']['doc'];
		$this->assertSame( 'bot.pdf', $value['name'] );
		$this->assertArrayNotHasKey( 'path', $value );
		$this->login_as( 'administrator' );
		$this->assertStringContainsString( '<td>bot.pdf <span class="description">(the file was not kept)</span></td>', $this->admin_screen( array( 'entry' => (string) $spam['entry_id'] ) ) );
	}

	/**
	 * The personal data export names the file and adds it to the archive.
	 */
	public function test_privacy_export_includes_files() {
		[ $id, $value ] = $this->stored_entry();

		$pairs = array_column( Privacy::export( 'ada@example.org' )['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'report.pdf (uploaded file, included in the pegwork-form-files folder of this export)', $pairs['Document'] );

		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestIncomplete( 'ZipArchive is not available.' );
		}
		$archive = wp_tempnam( 'export.zip' );
		$zip     = new \ZipArchive();
		$zip->open( $archive, \ZipArchive::OVERWRITE );
		$zip->addFromString( 'index.html', '<html></html>' );
		$zip->close();
		$request = wp_create_user_request( 'ada@example.org', 'export_personal_data' );
		$this->assertIsInt( $request );

		$this->assertSame( 1, Privacy::add_files( $archive, '', '', $request ) );
		$zip->open( $archive );
		$this->assertSame( self::pdf_bytes(), $zip->getFromName( 'pegwork-form-files/' . $id . '-doc-report.pdf' ) );
		$zip->close();
		wp_delete_file( $archive );
		$this->assertNotNull( Uploads::absolute( $value ), 'Exporting does not move the file.' );
	}
}
