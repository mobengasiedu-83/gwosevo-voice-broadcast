# IVR browser verification

Run `npm ci` to install locked development dependencies. Install Google Chrome (the test uses Playwright's chrome channel) and PHP with the project extensions/dependencies.

```
npm run test:ivr
npm run test:browser
IVR_BROWSER_CLIENT=1 npm run test:browser
```

Set TEST_PHP to a PHP executable if needed. The browser script exports the real Laravel /interactive page through an isolated PHPUnit/SQLite fixture, then serves that HTML and production public assets on 127.0.0.1:4319. It never uses production logins or sends real calls or payments. Do not run another fixture export or browser test simultaneously: they share the local port and temporary fixture filename.

Checks cover admin/client portal rendering at 1440px desktop and 390px mobile widths, mouse dragging, emulated touch dragging in Chrome, keyboard arrow movement, native required fields, restored keypad validity, horizontal page overflow and uncaught JavaScript errors. Screenshots are written to the OS temporary directory as ivr-desktop.png and ivr-mobile.png. The local graph may show a missing-recording issue because the isolated account has no approved media; this is expected.

Cards support arrow-key movement while their title button has focus; Enter/Space selects a card and keeps focus on its title. Properties use native labeled form controls. Screenshot inspection found a low-contrast client guide link on the gradient banner; its color was corrected.

Passed on 27 October 2026 for both admin and client fixtures. The frontend production build and the existing IVR smoke checks also passed. These checks do not verify actual backend form submission, audio/call execution, physical-device behavior, Firefox/Safari, screen readers, all portal pages or full WCAG compliance. Retain PHP/backend integration coverage and schedule those broader acceptance checks separately.
