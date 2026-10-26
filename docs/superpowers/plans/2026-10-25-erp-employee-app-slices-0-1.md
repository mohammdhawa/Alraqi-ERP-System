# ERP Employee App — Slices 0 and 1 (the copy and the foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create «موظف الراقي» as its own repository, copied from `doc_approval`'s `v11_employee_app`, and move its network and auth core onto the ERP. At the end, a pilot user can sign in with an employee number, change the temporary password, see their devices and sign out.

**Architecture:**
- **Slice 0 (Tasks 1–4)** copies the repository, renames it and strips what v1 does not ship: documents and the approver picker.
- **Slice 1 (Tasks 5–14)** replaces the network and auth core:
  - the ERP envelope and failures
  - a token pair with a single-flight refresh
  - the ERP account model
  - a five-state session (checking, signed out, must change password, not an employee, signed in)
  - the login, forced-change, primer and sessions screens
  - push registration on `/me/device-tokens`
- **Feature tabs** (home, attendance, leave, requests, notifications) keep calling their legacy paths until their own slice. Against the ERP they show their error state until then; that is expected.

**Tech Stack:** Flutter (Dart ≥ 3.3), Riverpod 2 (`StateNotifier`), Dio 5, go_router 14, flutter_secure_storage 9, firebase_messaging 15. New: `uuid`, `device_info_plus`.

**Spec:** `C:\Users\M.Hawa\Desktop\work\alraqi-erp-system\docs\superpowers\specs\2026-10-25-erp-employee-app-design.md` (§2, §3, §4). Contract: `alraqi-erp-system/docs/API_DOCUMENTATION.md` §1, §3, §6.5.

**Later slices** (2 home, 3 attendance + the ERP `/me/attendance/days` endpoint, 4 leave, 5 approvals, 6 notifications, 7 account and salary, 8 pilot hardening) each get their own plan when this one is done, written against the code as it then stands.

## Global Constraints

- **New repository:** `C:\Users\M.Hawa\Desktop\work\alraqi-erp-app`.
  - Dart package `alraqi_erp`.
  - Application id and iOS bundle id `tech.alraqi_company.erp`.
  - Display name «موظف الراقي».
  - Version `1.0.0+1`.
- **`doc_approval` is never modified.** The one exception is Task 1's push of `v11_employee_app` to its origin, done only on the owner's go-ahead.
- **Git writes happen only when the owner says so.** Every "Commit" step means: stage, show the message, and commit only on the owner's word. Nothing is pushed.
- **`API_BASE_URL`** comes only from `--dart-define` / `--dart-define-from-file`. It has no default, and an empty value fails at startup.
- **Refresh rules:**
  - It triggers on HTTP **401 only**, never on a 403.
  - It never runs for `/auth/login`, `/auth/refresh` or `/auth/password`.
  - Concurrent 401s cause exactly one `POST /auth/refresh`.
  - The new token pair is stored before any retry.
- **Login field:** the employee number, digits only, numeric keyboard. Arabic-Indic digits are converted to Western ones.
- **Text from the server is shown verbatim:** `message` and the `errors` bag. The app's own copy is Arabic and is given exactly in each task.
- **Every task's gate:** `flutter analyze` reports no issues, and `flutter test` passes.

## Review Focus

1. **An employee types their number on an Arabic keyboard (`٥٨٢٩٧٤٦٤٣`).** It must sign in exactly as `582974643` does. A digits-only filter would silently drop every digit. Pinned in Task 10.
2. **Login rate limit with only a `Retry-After` header.** The ERP sends the header, not legacy's `retry_after` body field. The countdown must still show. Pinned in Task 5 (parse) and Task 10 (screen).
3. **The rotated pair cannot be written** (secure storage fails after `/auth/refresh` succeeded). The old refresh token is already spent, so the app must send the user to login with a message, not loop or crash. Pinned in Task 7.
4. **HR resets a password while the app is closed.** `/auth/me` at boot returns `must_change_password: true`, and the app must open the forced-change screen, not home. Pinned in Task 9.
5. **Logout with no network.** Local tokens and caches must still be cleared, and the login screen shown. Pinned in Task 8.

## Before you start (owed by the owner)

- [ ] **The go-ahead to push** `v11_employee_app` to `doc_approval`'s origin (Task 1).
- [ ] **Firebase config:** register Android app `tech.alraqi_company.erp` (and the iOS app with the same bundle id) in the **legacy** Firebase project, and download `google-services.json` and `GoogleService-Info.plist`.
  - Task 2 can finish without them. The Android **device build** cannot: the Google services Gradle plugin refuses a config with no matching package. So Task 14 needs them.

## File map

**Slice 0**

| File | Change |
|---|---|
| repo root | cloned; `linux/`, `macos/`, `windows/`, `web/` deleted (the ERP app targets Android and iOS only) |
| `pubspec.yaml` | name, description, version, dependencies |
| `android/app/build.gradle.kts`, `AndroidManifest.xml`, `kotlin/tech/alraqi_company/erp/MainActivity.kt` | app id, label, Kotlin package |
| `ios/Runner.xcodeproj/project.pbxproj`, `ios/Runner/Info.plist` | bundle id, display name |
| `config/emulator.json`, `config/lan.example.json` | run configurations |
| `lib/core/utils/app_constants.dart` | `baseUrl` with no default; `requireBaseUrl()`; document constants removed |
| `lib/features/documents/**`, `test/features/documents/**`, `lib/core/widgets/approver_*`, `lib/core/widgets/reorderable_approver_list.dart`, `lib/core/validation/approver_validation.dart` | deleted |
| `lib/features/requests/**`, `lib/shared/widgets/status_chip.dart`, `lib/core/router/app_router.dart`, `lib/main.dart`, `lib/features/notifications/presentation/screens/notifications_screen.dart`, `lib/features/attendance/presentation/screens/leave_request_form_screen.dart`, `lib/features/attendance/data/leave_repository.dart` | document and approver references removed |
| `docs/design/employee-app-v2/project/` | replaced by the live canvas |
| `docs/superpowers/specs/`, `docs/superpowers/plans/` | the ERP spec and this plan copied in |

**Slice 1**

| File | Responsibility |
|---|---|
| `lib/core/errors/api_failure.dart` | failure type, status-derived codes, Arabic fallbacks |
| `lib/core/network/envelope.dart` (new) | reading `{success, message, data}` |
| `lib/core/storage/token_storage.dart` | the access + refresh pair and the cached user, all-or-nothing |
| `lib/core/network/token_refresher.dart` (new) | single-flight `POST /auth/refresh` |
| `lib/core/network/api_client.dart` | auth header, `X-Request-Id`, the 401 retry, failure mapping |
| `lib/features/auth/domain/user.dart` | the ERP account |
| `lib/core/services/device_identity.dart` (new) | `device_name` / `platform` for login |
| `lib/features/auth/data/auth_repository.dart` | login, me, password, logout, logout-all |
| `lib/features/auth/presentation/providers/auth_controller.dart` | the session state machine |
| `lib/core/router/app_router.dart` | redirects per state; new routes |
| `lib/features/auth/presentation/screens/login_screen.dart` | employee-number login |
| `lib/features/auth/presentation/screens/change_password_screen.dart` (new) | forced change |
| `lib/features/auth/presentation/screens/not_employee_screen.dart` (new) | an account with no employee |
| `lib/features/auth/presentation/screens/permissions_primer_screen.dart` (new) | location / camera / notifications primer |
| `lib/features/account/data/sessions_repository.dart`, `lib/features/account/domain/device_session.dart`, `lib/features/account/presentation/sessions_screen.dart` (new) | «الأجهزة» |
| `lib/core/services/push_notification_service.dart` | `/me/device-tokens` |
| `test/support/fake_http_adapter.dart` (new) | scripted responses and a request log, shared by every network test |

---

## Slice 0 — the copy

### Task 1: Clone, tag, and bring the design and docs across

**Files:**
- Create: the repository at `C:\Users\M.Hawa\Desktop\work\alraqi-erp-app`.
- Create: `docs/superpowers/specs/2026-10-25-erp-employee-app-design.md` and `docs/superpowers/plans/2026-10-25-erp-employee-app-slices-0-1.md`, copied from the ERP repo.
- Replace: `docs/design/employee-app-v2/project/*`, using the 20 files under the scratchpad folder `artifact-files\276aaa7c-dfcb-4cfd-a9e4-84201a987666\project\` (19 `.dc.html` plus `canvas.json`).

**Interfaces:** Produces the repository every later task works in. `main` is v11's tip. The tag is `forked-from-doc_approval-v11`.

- [ ] **Step 1: Push the ancestor (owner's go-ahead first).** In `doc_approval`, run `git push -u origin v11_employee_app`. Expected: the branch appears on `origin`. Without the go-ahead, skip this step and say so.
- [ ] **Step 2: Clone and reshape.**
  ```bash
  git clone --branch v11_employee_app C:/Users/M.Hawa/Desktop/work/doc_approval C:/Users/M.Hawa/Desktop/work/alraqi-erp-app
  ```
  Then, inside the new folder:
  1. `git branch -m v11_employee_app main`
  2. `git remote remove origin`
  3. `git tag forked-from-doc_approval-v11`

  Expected: `git log -1 --format=%h` matches `doc_approval`'s `v11_employee_app` (`ed3f32c`), and `git remote` prints nothing.
- [ ] **Step 3: Baseline.** Run `flutter pub get`, then `flutter test`, and record the pass count in your notes. This is the number Tasks 2–4 must not lose, apart from tests deleted along with their feature.
- [ ] **Step 4: Copy the design and docs.** Copy the 20 canvas files over `docs/design/employee-app-v2/project/`, and copy the spec and this plan into `docs/superpowers/`. Expected: `git status` shows 3 new `.dc.html` files (`Employee`, `FollowUp`, `People`), the changed ones, and the two docs.
- [ ] **Step 5: Commit**
  ```bash
  git add docs
  git commit -m "docs: the ERP app spec, slice 0-1 plan and the live design canvas"
  ```

### Task 2: The new identity

**Files:**
- Modify: `pubspec.yaml`
  - `name: alraqi_erp`
  - `description: «موظف الراقي» — the Al-Raqi ERP employee app.`
  - `version: 1.0.0+1`
- Modify: every `package:doc_approval/` import in `lib/` and `test/` → `package:alraqi_erp/`.
- Modify: `android/app/build.gradle.kts`: `namespace` and `applicationId` = `"tech.alraqi_company.erp"`.
- Move: `android/app/src/main/kotlin/com/example/doc_approval/MainActivity.kt` → `android/app/src/main/kotlin/tech/alraqi_company/erp/MainActivity.kt`, with `package tech.alraqi_company.erp`.
- Modify: `android/app/src/main/AndroidManifest.xml`: `android:label="موظف الراقي"`.
- Modify: `ios/Runner.xcodeproj/project.pbxproj`: every `com.example.docApproval` → `tech.alraqi_company.erp`, and `….RunnerTests` → `tech.alraqi_company.erp.RunnerTests`.
- Modify: `ios/Runner/Info.plist`: `CFBundleDisplayName` = `موظف الراقي`, `CFBundleName` = `alraqi_erp`.
- Modify: `lib/main.dart:272`: `title: 'موظف الراقي'`.
- Delete: `linux/`, `macos/`, `windows/`, `web/`.
- Delete: `android/app/google-services.json`. It names the legacy package and is replaced by the owner's file.

**Interfaces:** Produces the package name `alraqi_erp` that every later test imports.

- [ ] **Step 1: Apply the edits above.**
- [ ] **Step 2: Check nothing old remains.** Run `git grep -nE "doc_approval|docApproval|com\.example"`. Expected: hits only under `docs/`.
- [ ] **Step 3: Drop in the Firebase config if the owner has supplied it.** Put `google-services.json` in `android/app/` and `GoogleService-Info.plist` in `ios/Runner/`, and add the plist to the Runner target. If the files are not available yet, note it; Task 14 waits for them.
- [ ] **Step 4: Run the gate.** Run `flutter pub get`, then `flutter analyze` and `flutter test`. Expected: no issues, and the Task 1 pass count.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "chore: «موظف الراقي» — package alraqi_erp, id tech.alraqi_company.erp, version 1.0.0"
  ```

### Task 3: `API_BASE_URL` with no default

**Files:**
- Modify: `lib/core/utils/app_constants.dart`.
- Modify: `lib/main.dart`: call `AppConstants.requireBaseUrl()` first in `main()`, before `Firebase.initializeApp`.
- Modify: `lib/core/network/api_client.dart`: the constructor takes `required String baseUrl`, and `apiClientProvider` passes `AppConstants.requireBaseUrl()`.
- Create: `config/emulator.json` = `{"API_BASE_URL": "http://10.0.2.2:8000/api"}`.
- Create: `config/lan.example.json` = `{"API_BASE_URL": "http://192.168.1.10:8000/api"}`.
- Modify: `.gitignore`: add `config/lan.json`.
- Modify: `README.md`: a «التشغيل» section with the two run commands below. Replace the legacy README's content with a short description of this app.
- Test: `test/core/app_constants_test.dart`.

**Interfaces:**
- Produces: `static const String baseUrl = String.fromEnvironment('API_BASE_URL');`
- Produces: `static String requireBaseUrl([String value = baseUrl])`. It returns `value` without a trailing `/`. It throws `StateError('API_BASE_URL is not set. Run with --dart-define-from-file=config/emulator.json')` when `value.trim()` is empty.
- Produces: `ApiClient({required String baseUrl, …})`. Every test that builds an `ApiClient` passes `'http://erp.test/api'`.

- [ ] **Step 1: Write the failing test.**
  ```dart
  test('an empty base URL fails loudly', () {
    expect(() => AppConstants.requireBaseUrl(''), throwsStateError);
    expect(() => AppConstants.requireBaseUrl('  '), throwsStateError);
  });
  test('a set base URL is returned without a trailing slash', () {
    expect(AppConstants.requireBaseUrl('http://10.0.2.2:8000/api/'), 'http://10.0.2.2:8000/api');
  });
  ```
- [ ] **Step 2: Run it.** Run `flutter test test/core/app_constants_test.dart`. Expected: FAIL (`requireBaseUrl` is not defined).
- [ ] **Step 3: Implement** the constants change, the `main()` call, the `ApiClient` parameter and the config files. Update the existing tests that construct `ApiClient`.
- [ ] **Step 4: Run the gate.** Run `flutter test` and `flutter analyze`. Expected: PASS, and no issues.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(config): API_BASE_URL from a define file, no default"
  ```

Run commands for the README:

```bash
flutter run --dart-define-from-file=config/emulator.json
```

```bash
flutter run --dart-define-from-file=config/lan.json
```

### Task 4: Remove documents and the approver picker

**Files:**
- Delete: `lib/features/documents/`, `test/features/documents/`, `lib/core/widgets/approver_picker_sheet.dart`, `lib/core/widgets/reorderable_approver_list.dart`, `lib/core/validation/approver_validation.dart`.
- Modify: `lib/features/requests/domain/request_item.dart`. Remove:
  - `RequestKind.document`
  - `requestFromDocument`
  - `nextPendingApprovers`
  - `isAwaitingUser`
  - `mergeRequests`

  `RequestKind` keeps `leave` only.
- Modify: `lib/features/requests/presentation/providers/requests_providers.dart`.
  - Remove `SentDocuments*`, `documentsAwaitingMeProvider` and `_feed`'s document half.
  - `myRequestsFeedProvider` and `inboxFeedProvider` become leave-only.
  - `canReviewRequestsProvider` returns `currentUser?.isManager ?? false`. This is legacy's leave rule; Task 8 replaces it.
- Modify: `lib/features/requests/presentation/screens/requests_screen.dart`. Remove the «مستند من قالب» entry from the new-request sheet and the «عرض المزيد» documents pager.
- Modify: `lib/shared/widgets/status_chip.dart`. Remove the `DocumentStatus` overload. If nothing else uses the file, delete it.
- Modify: `lib/core/router/app_router.dart`. Remove the `documents/new` and `documents/:id` routes, the `/documents` guard in `resolveRedirect`, and their imports.
- Modify: `lib/main.dart`. Remove the `document_id` push branch (`router.push('/documents/$documentId')`).
- Modify: `lib/features/notifications/presentation/screens/notifications_screen.dart`. Remove the document tap target. A document notification shows its full text.
- Modify: `lib/features/attendance/presentation/screens/leave_request_form_screen.dart`. Remove the approvers section, its state, its validation, and the `departmentOf`/`sectionOf` callbacks.
- Modify: `lib/features/attendance/data/leave_repository.dart`. Stop sending `approver_ids` and `manager_id`.
- Modify: `lib/core/utils/app_constants.dart`. Remove `documentFileUrl`, `documentAttachmentFileUrl`, `documentStampedPdfUrl`, `maxDocumentBytes`, `maxAttachmentBytes`, `maxAttachments` and `attachmentAllowedExtensions`. Keep any of them that a remaining file still uses.
- Modify: `lib/core/errors/api_failure.dart`. Remove the codes only documents used:
  - `noPendingAction`
  - `documentAlreadyFinalized`
  - `invalidWorkflow`
  - `chiefCannotActYet`
  - `chiefAlreadyExists`
  - `counterNotInitialized`
  - `duplicateExportNumber`
  - `documentGenerationDisabled`

  Also remove `invalidIndicesFor` if nothing calls it.
- Modify: `pubspec.yaml`. For each of `syncfusion_flutter_pdfviewer`, `open_filex`, `share_plus` and `file_picker`, run `git grep -n "package:<name>" lib test`, and remove the dependency only when that finds nothing.
- Modify tests:
  - `test/core/personal_data_refresh_test.dart`, `test/core/router_shell_test.dart`, `test/features/auth/authorization_bootstrap_test.dart`, `test/features/home/home_screen_test.dart`: drop document fakes and expectations.
  - `test/features/requests/request_item_test.dart`, `requests_providers_test.dart`, `requests_screen_test.dart`: keep only the leave cases.

**Interfaces:**
- Produces: `canReviewRequestsProvider` (Provider<bool>), still defined, consumed by home and requests.
- Produces: `RequestItem` with `kind == RequestKind.leave` only.

- [ ] **Step 1: Write the failing tests.**
  - In `test/core/router_shell_test.dart`: `'/documents/7 is not a route any more'` builds the router and navigates to `/documents/7`, then expects `find.text('الصفحة غير موجودة.')`.
  - In `test/features/requests/requests_screen_test.dart`: `'the new-request sheet offers no document from a template'` opens «+ طلب جديد» and expects `find.text('مستند من قالب')` to find nothing.
- [ ] **Step 2: Run them.** Run `flutter test test/core/router_shell_test.dart test/features/requests/requests_screen_test.dart`. Expected: FAIL (the route and the sheet entry still exist).
- [ ] **Step 3: Make the deletions and edits listed above.**
- [ ] **Step 4: Check that nothing refers to the removed code.**
  - `git grep -nE "documents/|approver_picker|reorderable_approver|approver_validation|approver_ids"` should find hits only under `docs/`.
  - `flutter analyze` should report no issues.
  - `flutter test` should PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "chore: remove documents and the approver picker from the ERP app"
  ```

---

## Slice 1 — the foundation

### Task 5: The ERP envelope, failures and `X-Request-Id`

**Files:**
- Create: `test/support/fake_http_adapter.dart`.
- Create: `lib/core/network/envelope.dart`.
- Modify: `lib/core/errors/api_failure.dart`.
- Modify: `lib/core/network/api_client.dart`: `_failureFromResponse` and `onRequest`.
- Modify: `pubspec.yaml`: add `uuid: ^4.5.1`.
- Modify: every remaining `ApiErrorCode.notYourTurn` / `routeNotFound` use, in the leave files, to `forbidden` / `notFound`.
- Test: `test/core/api_failure_test.dart` (rewritten), `test/core/envelope_test.dart`.

**Interfaces:**
- Produces, in `test/support/fake_http_adapter.dart`:
  - `class FakeHttpAdapter implements HttpClientAdapter`
  - `void on(String method, String path, FakeReply Function(RequestOptions) reply)` registers a handler, matched in order, repeatable.
  - `final List<RequestOptions> requests` is the request log.
  - `FakeReply.json(int status, Object body, {Map<String, String> headers = const {}})` and `FakeReply.offline()`. The latter throws `DioException(type: connectionError)`.
- Produces, in `envelope.dart`:
  - `T envelopeData<T>(Response<dynamic> response)` returns `body['data'] as T`. It throws `ApiFailure(code: ApiErrorCode.unknown, message: arabicMessageFor(ApiErrorCode.unknown))` when the body is not a map or `data` is not a `T`.
  - `String? envelopeMessage(Response<dynamic> response)`.
- Produces, in `api_failure.dart`:
  - `enum ApiErrorCode { unauthenticated, forbidden, notFound, validationFailed, tooManyAttempts, serverError, network, unknown }`
  - `static ApiErrorCode fromStatus(int? status)`: 401 → unauthenticated, 403 → forbidden, 404 → notFound, 422 → validationFailed, 429 → tooManyAttempts, 500–599 → serverError, anything else → unknown.
  - `ApiFailure` gains `final String? requestId;`.
  - `toString()` returns `'ApiFailure(${code.name}, status: $statusCode, requestId: $requestId, $message)'`.
  - `fromString` is removed.
- These are the spec §4.1 cases under the existing names: `validationFailed` = Validation, `tooManyAttempts` = RateLimited, `serverError` = Server, `network` = Offline.

- [ ] **Step 1: Write the failing tests.**

  In `api_failure_test.dart`:
  ```dart
  test('the code comes from the HTTP status', () async {
    adapter.on('GET', '/x', (_) => FakeReply.json(403, {'success': false, 'message': 'تم تعطيل هذا الحساب.'}));
    final f = await failureOf(client.dio.get('/x'));
    expect(f.code, ApiErrorCode.forbidden);
    expect(f.message, 'تم تعطيل هذا الحساب.');
    expect(f.statusCode, 403);
  });
  test('422 maps the errors bag to fields', () async { /* body errors {'login': ['حقل رقم الموظف مطلوب.']} */
    expect(f.code, ApiErrorCode.validationFailed);
    expect(f.firstErrorFor('login'), 'حقل رقم الموظف مطلوب.');
  });
  test('429 reads Retry-After from the header alone', () async { /* headers {'retry-after': '42'}, no retry_after in body */
    expect(f.code, ApiErrorCode.tooManyAttempts);
    expect(f.retryAfter, 42);
  });
  test('every request carries an X-Request-Id and the failure keeps the echoed one', () async {
    /* reply 500 with headers {'x-request-id': 'srv-1'} */
    expect(adapter.requests.single.headers['X-Request-Id'], isA<String>());
    expect(f.requestId, 'srv-1');
    expect(f.code, ApiErrorCode.serverError);
  });
  test('no response is network', () async { /* FakeReply.offline() */ expect(f.code, ApiErrorCode.network); });
  ```

  In `envelope_test.dart`:
  - `envelopeData<Map<String, dynamic>>` of `{'success': true, 'message': 'تم', 'data': {'id': 1}}` returns `{'id': 1}`.
  - `envelopeData<Map<String, dynamic>>` of `{'success': true, 'data': []}` throws `isA<ApiFailure>()`.
  - `envelopeMessage` returns `'تم'`.
- [ ] **Step 2: Run them.** Run `flutter test test/core/api_failure_test.dart test/core/envelope_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - In `onRequest`: `options.headers.putIfAbsent('X-Request-Id', () => const Uuid().v4())`.
  - `requestId` comes from the response header `x-request-id`, falling back to the header that was sent.
  - Keep the 401 handling exactly as it is for now; Task 7 replaces it.
  - Trim `arabicMessageFor` to the eight codes.
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(network): the ERP envelope, status-derived failures and X-Request-Id"
  ```

### Task 6: The token pair in secure storage

**Files:**
- Modify: `lib/core/storage/token_storage.dart`.
- Modify: `lib/core/utils/app_constants.dart`. The keys become:
  - `accessTokenKey = 'access_token'`
  - `refreshTokenKey = 'refresh_token'`
  - `userKey = 'auth_user'`

  Remove `tokenKey`.
- Test: `test/features/auth/session_persistence_test.dart` (its `_FakeSecureStorage` stays).

**Interfaces:**
- Produces: `class TokenPair { const TokenPair({required this.accessToken, required this.refreshToken}); final String accessToken; final String refreshToken; factory TokenPair.fromJson(Map<String, dynamic> json); }`. It reads `access_token` and `refresh_token`, and lives in `token_storage.dart`.
- Produces, on `TokenStorage`:
  - `Future<String?> readAccessToken()`
  - `Future<String?> readRefreshToken()`
  - `Future<String?> readUserJson()`
  - `Future<void> writeSession({required TokenPair tokens, required String userJson})`: all three keys, or none.
  - `Future<void> writeTokens(TokenPair tokens)`: both tokens, or the store is wiped and `TokenStorageException` is thrown.
  - `Future<void> writeUserJson(String userJson)`.
  - `Future<void> clear()`: all three keys.

  `read()` is removed.

- [ ] **Step 1: Write the failing tests.**
  - `'writeSession stores the pair and the user'`: all three read back.
  - `'writeTokens replaces both tokens and keeps the user'`.
  - `'a write that fails twice leaves nothing behind'`: the existing failing-key fake, applied to `refresh_token`. Expect `throwsA(isA<TokenStorageException>())`, and all three reads return `null`.
  - `'clear removes all three keys'`.
- [ ] **Step 2: Run them.** Run `flutter test test/features/auth/session_persistence_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.** Use the same `_tryWrite`/`_wipe` recovery the file already has. `writeSession` writes access, then refresh, then user, and on failure wipes and retries once. Then update the callers of the removed API:
  - `ApiClient.onRequest` reads `readAccessToken()`.
  - In `AuthRepository`, `read()` becomes `readAccessToken()`.
  - `login` stores `writeSession(tokens: TokenPair(accessToken: token, refreshToken: (data['refresh_token'] as String?) ?? ''), userJson: …)`.
  - `restoreSession` stores the fresh user with `writeUserJson`.

  Task 8 rewrites both methods; this keeps the build green until then.
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(auth): store the access and refresh token pair"
  ```

### Task 7: The single-flight refresh

**Files:**
- Create: `lib/core/network/token_refresher.dart`.
- Modify: `lib/core/network/api_client.dart`: the 401 path, and the provider wiring.
- Test: `test/core/token_refresh_test.dart`.

**Interfaces:**
- Consumes: `TokenStorage.readRefreshToken/readAccessToken/writeTokens/clear` (Task 6), `FakeHttpAdapter` (Task 5).
- Produces: `sealed class RefreshResult`, with three cases:
  - `Refreshed(String accessToken)`
  - `RefreshRejected(String message)`
  - `RefreshUnavailable()`
- Produces: `class TokenRefresher { TokenRefresher({required Dio dio, required TokenStorage storage}); Future<RefreshResult> refresh(); }`.
  - `dio` is a bare client with the same `baseUrl` and no auth interceptor.
  - While one refresh is in flight, every call returns the same future.
  - `POST /auth/refresh` with `{'refresh_token': <stored>}`.
  - On 200: `writeTokens(TokenPair.fromJson(envelopeData(response)))`, then `Refreshed`.
  - No stored refresh token, a 401 or 422 from the server, or a `TokenStorageException`: `RefreshRejected` with the server's `message`, or `'انتهت الجلسة. الرجاء تسجيل الدخول مجدداً.'` when there is none.
  - No response, a 429 or a 5xx: `RefreshUnavailable`.
- Produces: `typedef OnSessionEnded = void Function(String? reason);`.
  - `ApiClient({required String baseUrl, required TokenStorage tokenStorage, required TokenRefresher refresher, required OnSessionEnded onSessionEnded})`.
  - `UnauthenticatedSignal.fire([String? reason])`; its listeners become `void Function(String? reason)`.
  - `apiClientProvider` builds the `TokenRefresher` and passes `signal.fire`.
- Produces: `const refreshExemptPaths = {'/auth/login', '/auth/refresh', '/auth/password'};`.

**The 401 path in `ApiClient`.** This is the subtle part, so here it is in full:

```text
onRequest:  token = readAccessToken(); set Authorization; options.extra['authToken'] = token
onResponse(401) when path ∉ refreshExemptPaths and options.extra['retried'] != true:
  current = readAccessToken()
  if current != null && current != options.extra['authToken']:   // another request already refreshed
      retry with current
  else switch await refresher.refresh():
      Refreshed(t)        → retry with t
      RefreshRejected(m)  → tokenStorage.clear(); onSessionEnded(m); reject (unauthenticated, message m)
      RefreshUnavailable  → reject (network) — the session is kept
retry = dio.fetch(options with Authorization: Bearer <token>, extra retried: true, extra authToken: <token>)
       → handler.resolve(retryResponse); a DioException from it → handler.reject(it)
onResponse(401) on a retried request → tokenStorage.clear(); onSessionEnded(server message); reject
onResponse(401) on an exempt path    → reject (unauthenticated) only: no clear, no onSessionEnded
```

- [ ] **Step 1: Write the failing tests.** All use one `FakeHttpAdapter` shared by the client and the refresher's bare `Dio`, and an in-memory `TokenStorage` fake.
  ```dart
  test('five concurrent 401s cause one refresh and five retries', () async {
    // /me/x answers 401 while Authorization is 'Bearer old', 200 for 'Bearer new'
    // /auth/refresh answers 200 {data: {access_token: 'new', refresh_token: 'r2', ...}} after a 50 ms delay
    final results = await Future.wait(List.generate(5, (_) => client.dio.get('/me/x')));
    expect(results.every((r) => r.statusCode == 200), isTrue);
    expect(adapter.requests.where((r) => r.path == '/auth/refresh'), hasLength(1));
    expect(await storage.readRefreshToken(), 'r2');
  });
  test('the new pair is stored before the first retry', () async {
    // the /me/x handler records storage.readAccessToken() when it sees 'Bearer new'
    expect(seenStoredToken, 'new');
  });
  test('a 401 for a request that used an already-rotated token retries without refreshing', () async {
    // storage holds 'old' when the request is sent; the /me/x handler, on seeing 'Bearer old',
    // writes the pair ('new', 'r2') to storage (another request's refresh finishing) and answers 401;
    // on 'Bearer new' it answers 200
    expect(response.statusCode, 200);
    expect(adapter.requests.where((r) => r.path == '/auth/refresh'), isEmpty);
  });
  test('a 403 never refreshes', () async { /* /me/x → 403 */ expect(refreshCalls, 0); expect(sessionEndedReasons, isEmpty); });
  test('a 401 on /auth/password never refreshes and keeps the session', () async {
    // body {'success': false, 'message': 'كلمة المرور الحالية غير صحيحة.'}
    expect(refreshCalls, 0);
    expect(await storage.readAccessToken(), 'old');
    expect(f.message, 'كلمة المرور الحالية غير صحيحة.');
  });
  test('a rejected refresh ends the session with the server message', () async {
    // /auth/refresh → 401 {'message': 'انتهت صلاحية رمز التحديث.'}
    expect(sessionEndedReasons, ['انتهت صلاحية رمز التحديث.']);
    expect(await storage.readAccessToken(), isNull);
  });
  test('a refresh with no network keeps the session', () async {
    // /auth/refresh → FakeReply.offline()
    expect(f.code, ApiErrorCode.network);
    expect(sessionEndedReasons, isEmpty);
    expect(await storage.readRefreshToken(), 'r1');
  });
  test('a refreshed pair that cannot be stored ends the session', () async {   // Review Focus 3
    // storage.writeTokens throws TokenStorageException
    expect(sessionEndedReasons.single, isNotNull);
    expect(f.code, ApiErrorCode.unauthenticated);
  });
  ```
- [ ] **Step 2: Run them.** Run `flutter test test/core/token_refresh_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement** `TokenRefresher` and the 401 path above. Update `AuthController`'s listener to the new `(String? reason)` signature; it ignores the reason until Task 9.
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(network): single-flight refresh on 401, never on 403 or the auth routes"
  ```

### Task 8: The ERP account and the auth repository

**Files:**
- Modify: `lib/features/auth/domain/user.dart`.
- Create: `lib/core/services/device_identity.dart`.
- Modify: `lib/features/auth/data/auth_repository.dart`.
- Modify: `pubspec.yaml`: add `device_info_plus: ^11.3.0`.
- Modify the readers of removed `User` members:
  - `lib/features/account/presentation/account_screen.dart:59`: drop the unit line. Slice 7 fills it from the profile.
  - `lib/features/home/presentation/home_screen.dart:120`: `user.isManager || user.isChief` → `ref.watch(canReviewRequestsProvider)`.
  - `lib/features/home/presentation/home_screen.dart:159`: drop the unit line. Slice 2 fills it from the summary.
  - `lib/features/attendance/presentation/widgets/hero_today_card.dart:122`: `attendanceEnabled: true` (decision Q7: every active employee records).
  - `lib/features/requests/presentation/providers/requests_providers.dart`: `canReviewRequestsProvider` returns `false`. The doc comment says approval work comes from the ERP inbox in slice 5, and no role grants it.
- Test: `test/features/auth/auth_repository_test.dart` (new), `test/features/auth/user_test.dart` (new).

**Interfaces:**
- Produces: `class User`, with:
  - `id` (int)
  - `name` (String?)
  - `email` (String?)
  - `isActive` (bool)
  - `mustChangePassword` (bool)
  - `roles` (List<String>)
  - `permissions` (Set<String>)
  - `sessionStale` (bool, default false)

  Its members:
  - `bool get hasEmployee => name != null;`
  - `bool can(String permission) => permissions.contains(permission);`
  - `factory User.fromJson(Map<String, dynamic>)` reads the §3.4 shape.
  - `Map<String, dynamic> toJson()`.
  - `User copyWith({bool? sessionStale, bool? mustChangePassword})`.

  The legacy members `role`, `isAdmin`, `isChief`, `isEmployee`, `isManager`, `canUseDocumentWorkflow`, `department*`, `section*`, `attendanceCheck`, `canViewAttendance` and `User.empty()` are removed.
- Produces: `class DeviceIdentity { const DeviceIdentity(this.name, this.platform); final String name; final String platform; }`.
  - `abstract interface class DeviceIdentitySource { Future<DeviceIdentity> read(); }`.
  - The real implementation uses `device_info_plus`. Android: `'${manufacturer} ${model}'`. iOS: `IosDeviceInfo.name`. Trim to 100 characters. `platform` is `'android'` or `'ios'`.
  - `deviceIdentitySourceProvider`.
- Produces: `AuthRepository(ApiClient api, TokenStorage storage, DeviceIdentitySource device)`, with:
  - `Future<User> login({required String login, required String password})`: `POST /auth/login` with `{login, password, device_name, platform}`. Then `writeSession(tokens: TokenPair.fromJson(data), userJson: jsonEncode(user.toJson()))` and return the `User` from `data['user']`.
  - `Future<User?> restoreSession()`:
    - With no access token, return `null`.
    - Otherwise `GET /auth/me`, then `writeUserJson`, and return the fresh user.
    - An `unauthenticated` failure (the refresh already failed) returns `null`.
    - A `network` or `serverError` failure returns the cached user with `copyWith(sessionStale: true)`. If there is no cached user, return `null`.
  - `Future<User> changePassword({required String currentPassword, required String newPassword})`: `POST /auth/password` with `{current_password, password: newPassword, password_confirmation: newPassword}`, then `writeTokens(TokenPair.fromJson(data))`, then `GET /auth/me`, then `writeUserJson`, and return that user.
  - `Future<void> logout()`: best-effort `POST /auth/logout`, then always `clear()`.
  - `Future<void> logoutAll()`: `POST /auth/logout-all`. Its failure is rethrown and nothing is cleared. On success, `clear()`.

  `AuthResult` is removed.

- [ ] **Step 1: Write the failing tests.**
  - `user_test.dart`:
    - `User.fromJson` of the §3.4 example has `name 'Test User'`, `mustChangePassword false` and `can('hr.employees.view') == true`.
    - `name: null` gives `hasEmployee == false`.
  - `auth_repository_test.dart`, using `FakeHttpAdapter`, a memory `TokenStorage` and a fixed `DeviceIdentity('Galaxy A54', 'android')`:
    - `'login sends login, password, device_name and platform'`: the request body equals `{'login': '582974643', 'password': 'secret123', 'device_name': 'Galaxy A54', 'platform': 'android'}`, and storage holds the pair from the §3.1 example.
    - `'changePassword replaces the pair and re-reads me'`: storage holds the §3.5 pair, `/auth/me` was called, and the user has `mustChangePassword == false`.
    - `'restoreSession offline keeps the cached user, marked stale'`.
    - `'logout with no network still clears everything'`: `/auth/logout` → `FakeReply.offline()`, and all three keys are `null` afterwards. (Review Focus 5.)
    - `'logoutAll that fails keeps the session'`.
- [ ] **Step 2: Run them.** Run `flutter test test/features/auth/user_test.dart test/features/auth/auth_repository_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement** the repository, the model and the call-site edits listed above. Also:
  - `AuthController.login(String login, String password)` calls the new `_repo.login(login: …, password: …)` and sets `AuthAuthenticated(user)`. Task 9 refines the state.
  - Update the existing auth tests' fakes to the new `login({login, password})` signature, which returns `User`.
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(auth): the ERP account, login by number, password change and logout"
  ```

### Task 9: The session state machine and its redirects

**Files:**
- Modify: `lib/features/auth/presentation/providers/auth_controller.dart`.
- Modify: `lib/core/router/app_router.dart`: `resolveRedirect` and the new routes `/change-password`, `/not-employee`, `/welcome`, `/account/sessions`. Each route points to a placeholder `Scaffold` until Tasks 10–12 supply its screen.
- Test: `test/features/auth/auth_controller_test.dart` (new), `test/core/router_shell_test.dart` (the redirect table).

**Interfaces:**
- Consumes: `AuthRepository` (Task 8), `UnauthenticatedSignal.fire(String?)` (Task 7).
- Produces: `sealed class AuthState`, with five cases:
  - `AuthInitial()`
  - `AuthUnauthenticated({String? reason})`
  - `AuthPasswordChangeRequired(User user)`
  - `AuthNotAnEmployee(User user)`
  - `AuthAuthenticated(User user)`
- Produces: `AuthState authStateFor(User user)`. `mustChangePassword` gives `AuthPasswordChangeRequired`; else `!hasEmployee` gives `AuthNotAnEmployee`; else `AuthAuthenticated`.
- Produces, on `AuthController`:
  - `Future<void> login(String login, String password)`
  - `Future<void> changePassword(String currentPassword, String newPassword)`
  - `Future<void> logout()`
  - `Future<void> logoutEverywhere()`

  The push session (`_startPushSession`) begins **only** on entering `AuthAuthenticated`. A forced logout sets `AuthUnauthenticated(reason: reason)`. `currentUserProvider` returns the user of any of the three user-bearing states.

  `logoutEverywhere()` runs in this order:
  1. `await _repo.logoutAll()`. If it throws, rethrow and leave the state unchanged.
  2. `_pushService.invalidateSession()`. The server has already dropped every push token.
  3. `await _sessionCleanup.run()`.
  4. `AuthUnauthenticated()`.
- Produces: `resolveRedirect` rules.

  | State | Allowed locations | Any other location goes to |
  |---|---|---|
  | `AuthInitial` | `/splash` | `/splash` |
  | `AuthUnauthenticated` | `/login` | `/login` |
  | `AuthPasswordChangeRequired` | `/change-password` | `/change-password` |
  | `AuthNotAnEmployee` | `/not-employee` | `/not-employee` |
  | `AuthAuthenticated` | everything except `/login`, `/change-password`, `/not-employee` | `/` |

  `/splash` is still left to the splash screen. `/welcome` is allowed only when authenticated.

- [ ] **Step 1: Write the failing tests.**
  - `auth_controller_test.dart`:
    - `'boot with must_change_password opens the forced change, not home'`: the repository's `restoreSession` returns a user with `mustChangePassword: true`. Expect `isA<AuthPasswordChangeRequired>()`, and that the push service's `beginSession` was not called. (Review Focus 4.)
    - `'a user with no employee is AuthNotAnEmployee'`.
    - `'changePassword moves to AuthAuthenticated and starts push'`.
    - `'a forced logout keeps the reason'`: `signal.fire('تم إبطال هذا الرمز. تم إنهاء جميع الجلسات حفاظًا على الأمان.')` gives `AuthUnauthenticated` with that `reason`.
    - `'logoutEverywhere that fails keeps the session'`: the repository's `logoutAll` throws `ApiFailure(code: network, …)`. Expect the state to stay `AuthAuthenticated` and `invalidateSession` not to have been called.
  - `router_shell_test.dart`: one `test` per table row above, calling `resolveRedirect(auth: …, matchedLocation: …)`.
- [ ] **Step 2: Run them.** Run `flutter test test/features/auth/auth_controller_test.dart test/core/router_shell_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(auth): five session states and the redirects that enforce them"
  ```

### Task 10: Login by employee number, and the not-an-employee screen

**Files:**
- Modify: `lib/features/auth/presentation/screens/login_screen.dart`.
- Create: `lib/features/auth/presentation/screens/not_employee_screen.dart`.
- Create: `lib/core/utils/digits.dart`.
- Modify: `lib/core/router/app_router.dart`: route `/not-employee` to the new screen.
- Test: `test/features/auth/login_screen_test.dart` (replaces `login_throttle_test.dart`), `test/core/digits_test.dart`.

**Interfaces:**
- Consumes: `AuthController.login`, and `AuthUnauthenticated.reason` (Task 9).
- Produces, in `digits.dart`:
  - `String westernDigits(String input)` maps `٠١٢٣٤٥٦٧٨٩` and `۰۱۲۳۴۵۶۷۸۹` to `0123456789`.
  - `class EmployeeNumberFormatter extends TextInputFormatter` applies `westernDigits`, then keeps `[0-9]` only.

**The login field:**
- label «رقم الموظف»
- hint «مثال: 582974643»
- `keyboardType: TextInputType.number`
- `inputFormatters: [EmployeeNumberFormatter()]`
- `autofillHints: [AutofillHints.username]`
- empty → «أدخل رقم الموظف.»

**Errors:**
- 401, 403 and the 422 `message` are shown verbatim in the existing error banner.
- `firstErrorFor('login')` goes under the field.
- 429 keeps the existing countdown, driven by `retryAfter`.
- When the state is `AuthUnauthenticated` with a `reason`, the banner shows that reason on arrival.

**The not-an-employee screen:**
- title «هذا التطبيق مخصّص للموظفين»
- body «هذا الحساب غير مرتبط بموظف. استخدم لوحة ERP على الويب.»
- a button «تسجيل الخروج» → `AuthController.logout()`

- [ ] **Step 1: Write the failing tests.**
  ```dart
  test('Arabic-Indic and Persian digits become Western', () {        // Review Focus 1
    expect(westernDigits('٥٨٢٩٧٤٦٤٣'), '582974643');
    expect(westernDigits('۵۸۲'), '582');
  });
  testWidgets('typing ٥٨٢٩٧٤٦٤٣ submits login 582974643', (tester) async { /* expect(repo.lastLogin, '582974643') */ });
  testWidgets('letters and spaces are dropped from the number', (tester) async { /* enter '58 2a9' → field text '5829' */ });
  testWidgets('the field opens a numeric keyboard', (tester) async {
    expect(tester.widget<TextField>(find.byType(TextField).first).keyboardType, TextInputType.number);
  });
  testWidgets('a suspended employee sees the server message', (tester) async {
    // repo.login throws ApiFailure(code: forbidden, statusCode: 403, message: 'لا يمكن تسجيل الدخول: الموظف موقوف عن العمل.')
    expect(find.text('لا يمكن تسجيل الدخول: الموظف موقوف عن العمل.'), findsOneWidget);
  });
  testWidgets('a 429 with Retry-After 60 locks the button and counts down', (tester) async { /* Review Focus 2 — keep the old throttle test's assertions */ });
  testWidgets('a forced-logout reason is shown on arrival', (tester) async { /* AuthUnauthenticated(reason: 'انتهت صلاحية رمز التحديث.') */ });
  testWidgets('not-employee screen signs out', (tester) async { /* tap «تسجيل الخروج» → controller.logout called */ });
  ```
- [ ] **Step 2: Run them.** Run `flutter test test/core/digits_test.dart test/features/auth/login_screen_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.** Delete `test/features/auth/login_throttle_test.dart` once its cases live in `login_screen_test.dart`.
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(auth): sign in with the employee number, Arabic digits included"
  ```

### Task 11: The forced password change and the permissions primer

**Files:**
- Create: `lib/features/auth/presentation/screens/change_password_screen.dart`.
- Create: `lib/features/auth/presentation/screens/permissions_primer_screen.dart`.
- Create: `lib/core/services/permission_prompter.dart`.
- Modify: `lib/core/router/app_router.dart`: route `/change-password` and `/welcome` to the new screens.
- Test: `test/features/auth/change_password_screen_test.dart`, `test/features/auth/permissions_primer_screen_test.dart`.

**Interfaces:**
- Consumes: `AuthController.changePassword` and `logout` (Task 9).
- Produces: `abstract interface class PermissionPrompter { Future<void> requestAll(); }`.
  - The real implementation calls `Geolocator.requestPermission()`, then `FirebaseMessaging.instance.requestPermission()`.
  - The camera is asked for by the OS at the first selfie.
  - `permissionPrompterProvider`.

**The change-password screen:**
- title «تغيير كلمة المرور»
- intro «كلمة المرور الحالية مؤقتة. اختر كلمة مرور جديدة للمتابعة.»
- Fields:
  - «كلمة المرور الحالية»
  - «كلمة المرور الجديدة»
  - «تأكيد كلمة المرور الجديدة»
- Client checks, in this order:
  1. empty → «هذا الحقل مطلوب.»
  2. new shorter than 8 → «كلمة المرور يجب أن تكون 8 أحرف على الأقل.»
  3. new longer than 128 → «كلمة المرور يجب ألا تتجاوز 128 حرفاً.»
  4. new equal to current → «يجب أن تختلف كلمة المرور الجديدة عن الحالية.»
  5. confirmation differs → «كلمتا المرور غير متطابقتين.»
- Server errors:
  - 401 → its `message` under the current-password field.
  - 422 → `firstErrorFor('password')` under the new-password field, otherwise `message` in the banner.
- Button «حفظ ومتابعة». On success, `context.go('/welcome')`.
- A text button «تسجيل الخروج» → `logout()`.

**The primer:**
- title «قبل أن تبدأ»
- Three rows, each with an icon:
  - «الموقع — لتسجيل مكان الحضور والانصراف.»
  - «الكاميرا — لالتقاط صورتك عند التسجيل.»
  - «الإشعارات — لإعلامك بقرارات طلباتك وتذكيرك بالانصراف.»
- Button «متابعة» → `await prompter.requestAll()`, then `context.go('/')`. A failure inside `requestAll` still navigates.

- [ ] **Step 1: Write the failing tests.**
  - Each of the five client checks shows its exact text, and `changePassword` is not called.
  - `'a wrong current password shows the server message on that field'`: the controller throws `ApiFailure(code: unauthenticated, statusCode: 401, message: 'كلمة المرور الحالية غير صحيحة.')`.
  - `'success goes to /welcome'`.
  - `'primer asks once and goes home'`: `requestAll` is called once, and `route:/` is shown (via `pumpTestApp`).
  - `'primer still goes home when asking throws'`.
- [ ] **Step 2: Run them.** Run `flutter test test/features/auth/change_password_screen_test.dart test/features/auth/permissions_primer_screen_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(auth): the forced password change and the permissions primer"
  ```

### Task 12: «الأجهزة» — sessions and sign out everywhere

**Files:**
- Create: `lib/features/account/domain/device_session.dart`.
- Create: `lib/features/account/data/sessions_repository.dart`.
- Create: `lib/features/account/presentation/sessions_screen.dart`.
- Modify: `lib/features/account/presentation/account_screen.dart`: a row «الأجهزة» → `context.push('/account/sessions')`.
- Modify: `lib/core/router/app_router.dart`: `/account/sessions` (on `rootNavigatorKey`).
- Test: `test/features/account/sessions_test.dart`.

**Interfaces:**
- Produces: `class DeviceSession`, with `id` (String), `deviceName` (String?), `platform` (String?), `signedInAt` (DateTime), `lastUsedAt` (DateTime?) and `isCurrent` (bool). It has `fromJson` for the §3.6 shape.
- Produces: `SessionsRepository(ApiClient api)`, with:
  - `Future<List<DeviceSession>> list()`: `GET /auth/sessions`.
  - `Future<void> end(String id)`: `DELETE /auth/sessions/{id}`.
  - `sessionsProvider` (a `FutureProvider.autoDispose<List<DeviceSession>>`).

**The screen:**
- title «الأجهزة»
- Each row shows:
  - the device name, or «جهاز غير معروف»
  - the platform label: android → «أندرويد», ios → «آيفون», web → «الويب»
  - «آخر استخدام» with `lastUsedAt` formatted by the existing `shared/utils/format.dart` helpers
  - the pill «هذا الجهاز» when `isCurrent`
- Other sessions have «إنهاء». A confirm dialog «إنهاء الجلسة على هذا الجهاز؟» leads to `end(id)` and a refresh of the list.
- The footer button «تسجيل الخروج من جميع الأجهزة». A confirm dialog «سيتم تسجيل خروجك من كل الأجهزة، ومنها هذا الجهاز.» leads to `AuthController.logoutEverywhere()`.
- A 404 on `end` (the session already ended) refreshes the list without an error.

- [ ] **Step 1: Write the failing tests.**
  - `'lists sessions newest first with the current one marked'`: the §3.6 example plus one more.
  - `'ending another session calls DELETE and reloads'`.
  - `'a 404 on end just reloads'`.
  - `'sign out everywhere calls logoutEverywhere after confirming'`.
- [ ] **Step 2: Run them.** Run `flutter test test/features/account/sessions_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(account): «الأجهزة» — see and end sessions"
  ```

### Task 13: Push registration on `/me/device-tokens`

**Files:**
- Modify: `lib/core/services/push_notification_service.dart`.
- Test: `test/core/push_registration_test.dart`.

**Interfaces:**
- Consumes: `beginSession`, `endSession` and `invalidateSession`, keeping their current names and ordering guarantees. `AuthController` (Task 9) calls `beginSession` only in `AuthAuthenticated`.
- Changes:
  - Registering becomes `POST /me/device-tokens` with `{'token': <fcm token>, 'platform': 'android' | 'ios'}`. The platform comes from `DeviceIdentitySource` (Task 8).
  - Unregistering becomes `DELETE /me/device-tokens` with body `{'token': <fcm token>}`.

- [ ] **Step 1: Write the failing tests.**
  - `'beginSession posts the token and platform'`: the body equals `{'token': 'fcm-1', 'platform': 'android'}`, and the path is `/me/device-tokens`.
  - `'a rotated token is posted again'`.
  - `'endSession deletes with the token in the body'`.
- [ ] **Step 2: Run them.** Run `flutter test test/core/push_registration_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS.
- [ ] **Step 5: Commit**
  ```bash
  git add -A
  git commit -m "feat(push): register the device on /me/device-tokens"
  ```

### Task 14: The slice gate against the local ERP

No new code. This proves slices 0–1 on a device. It needs the owner's Firebase files (see "Before you start").

- [ ] **Step 1: Prepare the ERP.** In `alraqi-erp-system`:
  - Run `php artisan migrate:fresh --seed`, then `php artisan serve --host=0.0.0.0 --port=8000`.
  - Read the employee number of the root employee linked to `test@example.com`: `php artisan tinker --execute="echo App\Modules\Auth\Models\User::where('email','test@example.com')->first()->employee->employee_number;"`.
- [ ] **Step 2: Sign in.** Run `flutter run --dart-define-from-file=config/emulator.json` on an emulator, sign in with that number and the seeder's password, and check:
  - the home tab opens
  - «الأجهزة» lists one session marked «هذا الجهاز», with the emulator's model as its name
- [ ] **Step 3: The forced change.**
  - Set `must_change_password = true` on that account in tinker, then cold-start the app. Expected: the forced-change screen.
  - Change the password. Expected: the primer, then home.
- [ ] **Step 4: The refresh race** (spec §5 slice 8 repeats this with the offline queue).
  - Set `SANCTUM_TOKEN_EXPIRATION=1` in the ERP `.env`, then `php artisan config:clear`.
  - Wait more than one minute, then pull to refresh on home. Expected: the ERP log shows one `POST /api/auth/refresh`, and the app stays signed in.
  - Restore the setting afterwards.
- [ ] **Step 5: Ending sessions.**
  - Sign in on a second emulator or device, and end that session from the first. Expected: the second lands on login with a message on its next request.
  - Use «تسجيل الخروج من جميع الأجهزة». Expected: both land on login.
- [ ] **Step 6: Expected gaps.** The home, دوامي, الطلبات and الإشعارات tabs show their error state: they still call legacy paths, which slices 2–6 replace. Record what each shows. A crash or a blank screen is a defect; an error card is expected.
- [ ] **Step 7: Run the final gate.** Run `flutter analyze` and `flutter test`. Expected: no issues, PASS. Report the test count against Task 1's baseline: the tests removed with documents, plus the tests added here.
