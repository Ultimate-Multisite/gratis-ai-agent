/**
 * E2E tests for image/file upload in the chat input (t122).
 *
 * Covers the upload feature added in PR #560 (t109):
 *   - Paperclip upload button visibility
 *   - File picker triggered by upload button
 *   - Drag-drop zone (is-drag-over class)
 *   - Thumbnail preview strip after attaching a file
 *   - Per-attachment remove button
 *   - Send button enabled when only attachments are present (no text)
 *
 * Tests run against the admin page at /wp-admin/admin.php?page=sd-ai-agent.
 * No live AI provider is required — file attachment state is purely client-side.
 *
 * Selector mapping (ChatRedesign sdaa-cr-* classes replace old ChatPanel classes):
 *   .sdaa-chat-panel:not(.is-compact)         → .sdaa-cr
 *   .sdaa-upload-btn                          → .sdaa-cr-icon-btn[aria-label="Attach file"]
 *   .sdaa-file-input                          → .sdaa-cr-input-toolbar-left input[type="file"]
 *   .sdaa-attachment-previews                 → .sdaa-cr-attachments
 *   .sdaa-attachment-thumb                    → .sdaa-cr-attachment-thumb
 *   .sdaa-input-area (drag-drop target)       → .sdaa-cr-input-frame
 *   .sdaa-attachment-thumb__img               → .sdaa-cr-attachment-thumb img
 *   .sdaa-attachment-thumb__ext               → .sdaa-cr-attachment-thumb span:first-child
 *   .sdaa-attachment-thumb__name              → .sdaa-cr-attachment-thumb-name
 *   .sdaa-attachment-thumb__remove            → .sdaa-cr-attachment-thumb-remove
 *
 * Run: pnpm run test:e2e:playwright
 */

const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const {
	loginToWordPress,
	goToAgentPage,
	getMessageInput,
	getSendButton,
} = require( './utils/wp-admin' );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Get the paperclip upload button locator.
 *
 * ChatRedesign InputArea renders the upload button as a .sdaa-cr-icon-btn with
 * aria-label "Attach file" inside .sdaa-cr-input-toolbar-left.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getUploadButton( page ) {
	return page
		.locator( '.sdaa-cr .sdaa-cr-icon-btn[aria-label="Attach file"]' )
		.first();
}

/**
 * Get the hidden file input locator.
 *
 * ChatRedesign InputArea renders the file input inside .sdaa-cr-input-toolbar-left.
 * It has no dedicated CSS class — scoped by container and type to stay precise.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getFileInput( page ) {
	return page
		.locator(
			'.sdaa-cr .sdaa-cr-input-toolbar-left input[type="file"]'
		)
		.first();
}

/**
 * Get the attachment preview strip locator.
 *
 * ChatRedesign InputArea renders .sdaa-cr-attachments when attachments > 0.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAttachmentPreviews( page ) {
	return page
		.locator( '.sdaa-cr .sdaa-cr-attachments' )
		.first();
}

/**
 * Get all attachment thumbnail locators.
 *
 * ChatRedesign InputArea renders .sdaa-cr-attachment-thumb per attached file.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getAttachmentThumbs( page ) {
	return page.locator( '.sdaa-cr .sdaa-cr-attachment-thumb' );
}

/**
 * Get the input frame (drag-drop target).
 *
 * ChatRedesign InputArea uses .sdaa-cr-input-frame as the drag-drop zone.
 * It receives `is-drag-over` on dragover and loses it on dragleave/drop.
 *
 * @param {import('@playwright/test').Page} page
 * @return {import('@playwright/test').Locator}
 */
function getInputArea( page ) {
	return page
		.locator( '.sdaa-cr .sdaa-cr-input-frame' )
		.first();
}

/**
 * Dispatch a drag event on a locator using a properly constructed DataTransfer
 * object created inside the browser context.
 *
 * WP 6.9 (and modern browsers) reject DragEventInit with a plain object for
 * `dataTransfer` — the property must be a real DataTransfer instance.
 * We work around this by constructing the event entirely inside page.evaluate().
 *
 * @param {import('@playwright/test').Page}    page
 * @param {import('@playwright/test').Locator} locator  Target element locator.
 * @param {'dragover'|'dragleave'|'drop'}      eventType
 */
async function dispatchDragEvent( page, locator, eventType ) {
	// Resolve the element handle so we can pass it into evaluate.
	const elementHandle = await locator.elementHandle();
	await page.evaluate(
		( { el, type } ) => {
			const dt = new DataTransfer();
			const event = new DragEvent( type, {
				bubbles: true,
				cancelable: true,
				dataTransfer: dt,
			} );
			el.dispatchEvent( event );
		},
		{ el: elementHandle, type: eventType }
	);
}

/**
 * Create a minimal 1×1 PNG as a Buffer (valid PNG header + IDAT).
 * Used to simulate a real image file without needing a fixture on disk.
 *
 * @return {Buffer} Raw PNG bytes.
 */
function createMinimalPng() {
	// Minimal valid 1×1 white PNG (137 bytes).
	return Buffer.from(
		'89504e470d0a1a0a0000000d49484452000000010000000108020000009001' +
			'2e00000000c4944415478016360f8cfc00000000200016e0213500000000049454e44ae426082',
		'hex'
	);
}

/**
 * Attach a synthetic PNG file to the hidden file input via setInputFiles.
 * This bypasses the OS file picker and directly sets the file on the input.
 *
 * Waits for the file input to be attached to the DOM before setting files,
 * since the ChatRedesign InputArea is lazy-mounted after window.sdAiAgentChat
 * becomes available.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [fileName='test-image.png']
 */
async function attachPngViaInput( page, fileName = 'test-image.png' ) {
	const fileInput = getFileInput( page );
	await expect( fileInput ).toBeAttached( { timeout: 30_000 } );
	await fileInput.setInputFiles( {
		name: fileName,
		mimeType: 'image/png',
		buffer: createMinimalPng(),
	} );
}

/**
 * Attach a synthetic plain-text file to the hidden file input.
 *
 * Waits for the file input to be attached to the DOM before setting files.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [fileName='test-doc.txt']
 */
async function attachTextViaInput( page, fileName = 'test-doc.txt' ) {
	const fileInput = getFileInput( page );
	await expect( fileInput ).toBeAttached( { timeout: 30_000 } );
	await fileInput.setInputFiles( {
		name: fileName,
		mimeType: 'text/plain',
		buffer: Buffer.from( 'Hello, world!' ),
	} );
}

// ---------------------------------------------------------------------------
// Test suites
// ---------------------------------------------------------------------------

test.describe( 'Chat Upload - Upload Button (t122)', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
		// Wait for ChatRedesign to mount. ChatRoute polls for window.sdAiAgentChat
		// (exposed by admin-page.js), so the .sdaa-cr root may appear after a short
		// delay. Both .sdaa-cr and the file input must be visible before interacting.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
	} );

	test( 'paperclip upload button is visible in the input row', async ( {
		page,
	} ) => {
		const uploadBtn = getUploadButton( page );
		await expect( uploadBtn ).toBeVisible();
	} );

	test( 'upload button has accessible label', async ( { page } ) => {
		const uploadBtn = getUploadButton( page );
		// The button renders aria-label from the `label` prop.
		const label = await uploadBtn.getAttribute( 'aria-label' );
		expect( label ).toBeTruthy();
		expect( label.toLowerCase() ).toContain( 'attach' );
	} );

	test( 'upload button stays enabled while sending (always-on input)', async ( {
		page,
	} ) => {
		// The always-on message input (message-queue feature) keeps the upload
		// button enabled even while the agent is processing. Users can attach
		// files to a queued message while a job is in-flight.
		// Intercept POST /run so the job stays "processing" long enough to
		// check the button state. Hold the /run response until we resolve.
		let resolveRun;
		const runPending = new Promise( ( res ) => {
			resolveRun = res;
		} );

		await page.route(
			( url ) =>
				decodeURIComponent( url.toString() ).includes(
					'sd-ai-agent/v1/run'
				),
			async ( route ) => {
				await runPending;
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { job_id: 'e2e-upload-job-1' } ),
				} );
			}
		);

		const input = getMessageInput( page );
		await input.fill( 'Test' );
		await input.press( 'Enter' );

		// While the /run response is pending, the upload button should remain
		// ENABLED — users can attach files to messages queued during processing.
		const uploadBtn = getUploadButton( page );
		await expect( uploadBtn ).toBeEnabled( { timeout: 5_000 } );

		// Unblock the /run response so the test can clean up.
		resolveRun();
	} );

	test( 'clicking upload button triggers the hidden file input', async ( {
		page,
	} ) => {
		// Listen for the file chooser that the hidden input opens.
		const fileChooserPromise = page.waitForEvent( 'filechooser', {
			timeout: 5_000,
		} );

		const uploadBtn = getUploadButton( page );
		await uploadBtn.click();

		// If the file chooser opens, the button correctly triggered the input.
		const fileChooser = await fileChooserPromise;
		expect( fileChooser ).toBeTruthy();
	} );
} );

test.describe( 'Chat Upload - Drag-Drop Zone (t122)', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
		// Wait for ChatRedesign InputArea to be ready before dispatching drag events.
		await page
			.locator( '.sdaa-cr .sdaa-cr-input-frame' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
	} );

	test( 'input area gains is-drag-over class on dragover', async ( {
		page,
	} ) => {
		const inputArea = getInputArea( page );

		// Dispatch a dragover event with a properly constructed DataTransfer.
		// Plain objects are rejected by WP 6.9+ — must use a real DataTransfer.
		await dispatchDragEvent( page, inputArea, 'dragover' );

		await expect( inputArea ).toHaveClass( /is-drag-over/ );
	} );

	test( 'is-drag-over class is removed on dragleave', async ( { page } ) => {
		const inputArea = getInputArea( page );

		// Simulate dragover then dragleave using real DataTransfer objects.
		await dispatchDragEvent( page, inputArea, 'dragover' );
		await expect( inputArea ).toHaveClass( /is-drag-over/ );

		await dispatchDragEvent( page, inputArea, 'dragleave' );
		await expect( inputArea ).not.toHaveClass( /is-drag-over/ );
	} );

	// ChatRedesign InputArea uses CSS-only is-drag-over styling on the frame
	// element. A separate drop-overlay text element has not yet been added.
	test.fixme(
		'drop overlay text is shown while dragging over',
		async ( { page } ) => {
			const inputArea = getInputArea( page );

			await dispatchDragEvent( page, inputArea, 'dragover' );

			// The drop overlay renders "Drop files here" text.
			// TODO: Add .sdaa-cr-drop-overlay element to InputArea and update selector.
			const dropOverlay = page.locator( '.sdaa-cr-drop-overlay' );
			await expect( dropOverlay ).toBeVisible();
			await expect( dropOverlay ).toContainText( 'Drop files here' );
		}
	);

	test( 'drop overlay is hidden when not dragging', async ( { page } ) => {
		// On initial load, no drag is active — the input frame should not have
		// the is-drag-over class (ChatRedesign uses CSS-only styling; no separate
		// overlay element is rendered).
		const inputArea = getInputArea( page );
		await expect( inputArea ).not.toHaveClass( /is-drag-over/ );
	} );
} );

test.describe( 'Chat Upload - Thumbnail Preview (t122)', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
		// Wait for ChatRedesign InputArea to be ready before attaching files.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
	} );

	test( 'attachment preview strip is hidden before any file is attached', async ( {
		page,
	} ) => {
		// .sdaa-cr-attachments only renders when attachments.length > 0.
		const previews = getAttachmentPreviews( page );
		await expect( previews ).not.toBeVisible();
	} );

	test( 'thumbnail preview appears after attaching an image', async ( {
		page,
	} ) => {
		await attachPngViaInput( page );

		// The preview strip should now be visible.
		const previews = getAttachmentPreviews( page );
		await expect( previews ).toBeVisible( { timeout: 5_000 } );

		// At least one thumbnail should be present.
		const thumbs = getAttachmentThumbs( page );
		await expect( thumbs ).toHaveCount( 1 );
	} );

	test( 'thumbnail shows an <img> element for image attachments', async ( {
		page,
	} ) => {
		await attachPngViaInput( page );

		// ChatRedesign InputArea renders <img> inside .sdaa-cr-attachment-thumb
		// for image attachments.
		const thumbImg = page.locator(
			'.sdaa-cr-attachment-thumb img'
		);
		await expect( thumbImg ).toBeVisible( { timeout: 5_000 } );

		// The src should be a data URL (base64-encoded image).
		const src = await thumbImg.getAttribute( 'src' );
		expect( src ).toMatch( /^data:image\// );
	} );

	test( 'thumbnail shows file extension badge for non-image attachments', async ( {
		page,
	} ) => {
		await attachTextViaInput( page );

		// ChatRedesign InputArea renders a <span> with the extension text as
		// the first child of .sdaa-cr-attachment-thumb for non-image files.
		const extBadge = page.locator(
			'.sdaa-cr-attachment-thumb span:first-child'
		);
		await expect( extBadge ).toBeVisible( { timeout: 5_000 } );
		await expect( extBadge ).toContainText( 'TXT' );
	} );

	test( 'thumbnail shows the file name', async ( { page } ) => {
		await attachPngViaInput( page, 'my-screenshot.png' );

		// ChatRedesign InputArea renders .sdaa-cr-attachment-thumb-name for the
		// file name label.
		const thumbName = page.locator(
			'.sdaa-cr-attachment-thumb-name'
		);
		await expect( thumbName ).toBeVisible( { timeout: 5_000 } );
		await expect( thumbName ).toContainText( 'my-screenshot.png' );
	} );

	test( 'multiple files produce multiple thumbnails', async ( { page } ) => {
		const fileInput = getFileInput( page );
		await expect( fileInput ).toBeAttached( { timeout: 30_000 } );
		await fileInput.setInputFiles( [
			{
				name: 'image1.png',
				mimeType: 'image/png',
				buffer: createMinimalPng(),
			},
			{
				name: 'image2.png',
				mimeType: 'image/png',
				buffer: createMinimalPng(),
			},
		] );

		const thumbs = getAttachmentThumbs( page );
		await expect( thumbs ).toHaveCount( 2, { timeout: 5_000 } );
	} );
} );

test.describe( 'Chat Upload - Remove Button (t122)', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
		// Wait for ChatRedesign InputArea to be ready before attaching files.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
	} );

	test( 'each thumbnail has a remove button', async ( { page } ) => {
		await attachPngViaInput( page );

		// ChatRedesign InputArea renders .sdaa-cr-attachment-thumb-remove per thumb.
		const removeBtn = page.locator(
			'.sdaa-cr-attachment-thumb-remove'
		);
		await expect( removeBtn ).toBeVisible( { timeout: 5_000 } );
	} );

	test( 'remove button has accessible aria-label', async ( { page } ) => {
		await attachPngViaInput( page );

		const removeBtn = page.locator(
			'.sdaa-cr-attachment-thumb-remove'
		);
		await expect( removeBtn ).toBeVisible( { timeout: 5_000 } );

		const label = await removeBtn.getAttribute( 'aria-label' );
		expect( label ).toBeTruthy();
		expect( label.toLowerCase() ).toContain( 'remove' );
	} );

	test( 'clicking remove button removes the thumbnail', async ( { page } ) => {
		await attachPngViaInput( page );

		// Confirm thumbnail is present.
		const thumbs = getAttachmentThumbs( page );
		await expect( thumbs ).toHaveCount( 1, { timeout: 5_000 } );

		// Click the remove button.
		const removeBtn = page.locator(
			'.sdaa-cr-attachment-thumb-remove'
		);
		await removeBtn.click();

		// Thumbnail should be gone.
		await expect( thumbs ).toHaveCount( 0, { timeout: 5_000 } );

		// Preview strip should also disappear (.sdaa-cr-attachments not rendered when empty).
		const previews = getAttachmentPreviews( page );
		await expect( previews ).not.toBeVisible();
	} );

	test( 'removing one of multiple thumbnails leaves the rest', async ( {
		page,
	} ) => {
		const fileInput = getFileInput( page );
		await expect( fileInput ).toBeAttached( { timeout: 30_000 } );
		await fileInput.setInputFiles( [
			{
				name: 'first.png',
				mimeType: 'image/png',
				buffer: createMinimalPng(),
			},
			{
				name: 'second.png',
				mimeType: 'image/png',
				buffer: createMinimalPng(),
			},
		] );

		const thumbs = getAttachmentThumbs( page );
		await expect( thumbs ).toHaveCount( 2, { timeout: 5_000 } );

		// Remove the first thumbnail.
		const firstRemoveBtn = page
			.locator( '.sdaa-cr-attachment-thumb-remove' )
			.first();
		await firstRemoveBtn.click();

		// Only one thumbnail should remain.
		await expect( thumbs ).toHaveCount( 1, { timeout: 5_000 } );
	} );
} );

test.describe( 'Chat Upload - Send Button State (t122)', () => {
	test.beforeEach( async ( { page } ) => {
		await loginToWordPress( page );
		await goToAgentPage( page );
		// Wait for ChatRedesign InputArea to be ready before attaching files.
		await page
			.locator( '.sdaa-cr' )
			.waitFor( { state: 'visible', timeout: 30_000 } );
	} );

	test( 'send button is enabled when only an attachment is present (no text)', async ( {
		page,
	} ) => {
		// Confirm send button starts disabled (no text, no attachments).
		const sendButton = getSendButton( page );
		await expect( sendButton ).toBeDisabled();

		// Attach a file without typing any text.
		await attachPngViaInput( page );

		// Send button should now be enabled — attachments alone allow sending.
		await expect( sendButton ).toBeEnabled( { timeout: 5_000 } );
	} );

	test( 'send button is disabled after removing the only attachment', async ( {
		page,
	} ) => {
		await attachPngViaInput( page );

		const sendButton = getSendButton( page );
		await expect( sendButton ).toBeEnabled( { timeout: 5_000 } );

		// Remove the attachment.
		const removeBtn = page.locator(
			'.sdaa-cr-attachment-thumb-remove'
		);
		await removeBtn.click();

		// No text, no attachments — send button should be disabled again.
		await expect( sendButton ).toBeDisabled( { timeout: 5_000 } );
	} );

	test( 'attachments are cleared after sending a message', async ( {
		page,
	} ) => {
		// Intercept POST /run so the job completes quickly.
		await page.route(
			( url ) => decodeURIComponent( url.toString() ).includes( 'sd-ai-agent/v1/run' ),
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { job_id: 'e2e-upload-clear-job' } ),
				} );
			}
		);
		// Intercept GET /job/:id — return complete immediately.
		await page.route(
			( url ) => decodeURIComponent( url.toString() ).includes( 'sd-ai-agent/v1/job/' ),
			async ( route ) => {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						status: 'complete',
						reply: 'OK',
					} ),
				} );
			}
		);

		// Attach a file and type a message.
		await attachPngViaInput( page );
		const input = getMessageInput( page );
		await input.fill( 'Here is an image' );

		// Confirm thumbnail is present before sending.
		const thumbs = getAttachmentThumbs( page );
		await expect( thumbs ).toHaveCount( 1, { timeout: 5_000 } );

		// Send the message.
		await input.press( 'Enter' );

		// After sending, the attachment strip should be cleared.
		const previews = getAttachmentPreviews( page );
		await expect( previews ).not.toBeVisible( { timeout: 5_000 } );
	} );
} );
