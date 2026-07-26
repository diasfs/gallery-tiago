# Separate Processing Statuses Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single photo `processingStatus` with independent `mediaStatus`, `facesStatus`, and `tagsStatus` (plus prefixed lines in `processingError`) across API, workers, and admin UI per `docs/superpowers/specs/2026-07-26-separate-processing-statuses-design.md`.

**Architecture:** Three Doctrine enum columns replace `processing_status`. PHP `ProcessingErrorBag` merges/clears `media:` / `faces:` / `tags:` lines in the shared `processing_error` text. Convert/reprocess/upload set stage fields; `worker-faces` owns `faces_status`; `worker-tags` owns `tags_status`. Admin JSON and Vue screens drop `processingStatus` and show three badges; polling treats media `pending`/`converting` and faces/tags `detecting` as in-flight.

**Tech Stack:** Symfony 7 + Doctrine migrations, PHPUnit, Python workers (psycopg), Vue 3 + Vitest.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-26-separate-processing-statuses-design.md` is authoritative
- Remove `processing_status` / `processingStatus` entirely (no derived aggregate)
- Error prefixes exactly: `media:`, `faces:`, `tags:` (one line per stage, joined by `\n`)
- Polling inFlight: media in `{pending,converting}` OR faces=`detecting` OR tags=`detecting` (not faces/tags `pending` alone)
- Migration mapping of old statuses must match the spec table exactly
- Prefer `data-testid` in new Vitest selectors
- After code changes: `graphify update .` from repo root
- Do not edit the plan file’s narrative except to check off steps

## File map

```
apps/api/
  src/Enum/MediaStatus.php              # NEW
  src/Enum/FacesStatus.php              # NEW
  src/Enum/TagsStatus.php               # NEW
  src/Enum/ProcessingStatus.php         # DELETE after callers updated
  src/Service/ProcessingErrorBag.php    # NEW — set/clear prefixed lines
  src/Entity/Photo.php                  # three status columns
  src/Service/PhotoReprocessor.php      # stage-aware reprocess
  src/MessageHandler/ConvertMediaHandler.php
  src/Controller/Api/Admin/PhotoController.php
  src/Controller/Api/Admin/PhotoUploadController.php
  migrations/Version20260726180000.php  # NEW
  tests/Service/ProcessingErrorBagTest.php  # NEW
  tests/MessageHandler/ConvertMediaHandlerTest.php
  tests/Api/PhotoUploadTest.php
  tests/Api/PersonMergeTest.php         # reprocess assertions if they touch status

apps/worker-faces/
  db.py                                 # faces_status + error bag
  main.py
  tests/test_processing_error.py        # NEW

apps/worker-tags/
  db.py                                 # set_tags_status
  main.py                               # write tags_status on success/fail
  tests/test_processing_error.py        # NEW (same helper logic)
  tests/test_db.py                      # extend if needed

apps/web/
  src/api/types.ts
  src/views/admin/AlbumPhotosView.vue
  src/views/admin/AlbumPhotosView.spec.ts
  src/views/admin/PhotoEditView.vue
  src/views/admin/AlbumsView.spec.ts    # fixture fields only
```

---

### Task 1: ProcessingErrorBag + stage enums (PHP)

**Files:**
- Create: `apps/api/src/Service/ProcessingErrorBag.php`
- Create: `apps/api/src/Enum/MediaStatus.php`
- Create: `apps/api/src/Enum/FacesStatus.php`
- Create: `apps/api/src/Enum/TagsStatus.php`
- Create: `apps/api/tests/Service/ProcessingErrorBagTest.php`

**Interfaces:**
- Produces:
  - `MediaStatus`: `Pending|Converting|Done|Failed` (values `pending|converting|done|failed`)
  - `FacesStatus`: `Pending|Detecting|Done|Failed`
  - `TagsStatus`: `Pending|Detecting|Done|Failed`
  - `ProcessingErrorBag::set(?string $current, string $stage, string $message): string`
  - `ProcessingErrorBag::clear(?string $current, string $stage): ?string`
- Consumes: nothing

- [ ] **Step 1: Write the failing test**

Create `apps/api/tests/Service/ProcessingErrorBagTest.php`:

```php
<?php

namespace App\Tests\Service;

use App\Service\ProcessingErrorBag;
use PHPUnit\Framework\TestCase;

final class ProcessingErrorBagTest extends TestCase
{
    public function testSetAddsPrefixedLine(): void
    {
        $this->assertSame(
            'faces: boom',
            ProcessingErrorBag::set(null, 'faces', 'boom'),
        );
    }

    public function testSetReplacesExistingStageLineAndKeepsOthers(): void
    {
        $current = "media: disk full\nfaces: old";
        $this->assertSame(
            "media: disk full\nfaces: new",
            ProcessingErrorBag::set($current, 'faces', 'new'),
        );
    }

    public function testClearRemovesStageLineAndReturnsNullWhenEmpty(): void
    {
        $this->assertSame(
            'media: disk full',
            ProcessingErrorBag::clear("media: disk full\nfaces: boom", 'faces'),
        );
        $this->assertNull(ProcessingErrorBag::clear('faces: boom', 'faces'));
    }

    public function testSetNormalizesMessageWhitespace(): void
    {
        $this->assertSame(
            'tags: timeout',
            ProcessingErrorBag::set(null, 'tags', "  timeout\n"),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T api php bin/phpunit tests/Service/ProcessingErrorBagTest.php
```

Expected: FAIL (class not found).

- [ ] **Step 3: Write minimal implementation**

`apps/api/src/Enum/MediaStatus.php`:

```php
<?php

namespace App\Enum;

enum MediaStatus: string
{
    case Pending = 'pending';
    case Converting = 'converting';
    case Done = 'done';
    case Failed = 'failed';
}
```

`apps/api/src/Enum/FacesStatus.php`:

```php
<?php

namespace App\Enum;

enum FacesStatus: string
{
    case Pending = 'pending';
    case Detecting = 'detecting';
    case Done = 'done';
    case Failed = 'failed';
}
```

`apps/api/src/Enum/TagsStatus.php`:

```php
<?php

namespace App\Enum;

enum TagsStatus: string
{
    case Pending = 'pending';
    case Detecting = 'detecting';
    case Done = 'done';
    case Failed = 'failed';
}
```

`apps/api/src/Service/ProcessingErrorBag.php`:

```php
<?php

namespace App\Service;

final class ProcessingErrorBag
{
    private const STAGES = ['media', 'faces', 'tags'];

    public static function set(?string $current, string $stage, string $message): string
    {
        self::assertStage($stage);
        $message = trim($message);
        $lines = self::lines($current);
        $lines[$stage] = $stage.': '.$message;

        return self::join($lines);
    }

    public static function clear(?string $current, string $stage): ?string
    {
        self::assertStage($stage);
        $lines = self::lines($current);
        unset($lines[$stage]);
        $joined = self::join($lines);

        return '' === $joined ? null : $joined;
    }

    /** @return array<string, string> stage => full line */
    private static function lines(?string $current): array
    {
        $out = [];
        if (null === $current || '' === trim($current)) {
            return $out;
        }
        foreach (preg_split("/\r\n|\n|\r/", $current) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            foreach (self::STAGES as $stage) {
                if (str_starts_with($line, $stage.':')) {
                    $out[$stage] = $line;
                    continue 2;
                }
            }
            // Keep unrecognized legacy lines under a synthetic key so clear/set
            // of known stages does not destroy them.
            $out['_'.$line] = $line;
        }

        return $out;
    }

    /** @param array<string, string> $lines */
    private static function join(array $lines): string
    {
        $ordered = [];
        foreach (self::STAGES as $stage) {
            if (isset($lines[$stage])) {
                $ordered[] = $lines[$stage];
                unset($lines[$stage]);
            }
        }
        foreach ($lines as $line) {
            $ordered[] = $line;
        }

        return implode("\n", $ordered);
    }

    private static function assertStage(string $stage): void
    {
        if (!\in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown processing stage "%s".', $stage));
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec -T api php bin/phpunit tests/Service/ProcessingErrorBagTest.php
```

Expected: OK (4 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/api/src/Enum/MediaStatus.php apps/api/src/Enum/FacesStatus.php apps/api/src/Enum/TagsStatus.php \
  apps/api/src/Service/ProcessingErrorBag.php apps/api/tests/Service/ProcessingErrorBagTest.php
git commit -m "$(cat <<'EOF'
feat(api): add stage status enums and processing error bag

EOF
)"
```

---

### Task 2: Photo entity + Doctrine migration

**Files:**
- Modify: `apps/api/src/Entity/Photo.php`
- Create: `apps/api/migrations/Version20260726180000.php`
- Delete: `apps/api/src/Enum/ProcessingStatus.php` (only after Task 3 removes last references — if still referenced, defer delete to Task 3)

**Interfaces:**
- Produces on `Photo`:
  - `getMediaStatus(): MediaStatus` / `setMediaStatus(MediaStatus): static`
  - `getFacesStatus(): FacesStatus` / `setFacesStatus(FacesStatus): static`
  - `getTagsStatus(): TagsStatus` / `setTagsStatus(TagsStatus): static`
  - defaults all `Pending`
- Consumes: enums from Task 1
- DB columns: `media_status`, `faces_status`, `tags_status` VARCHAR(20) NOT NULL; drop `processing_status`

- [ ] **Step 1: Update Photo entity**

Replace the single status field and accessors with:

```php
use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;

#[ORM\Column(length: 20, enumType: MediaStatus::class)]
private MediaStatus $mediaStatus = MediaStatus::Pending;

#[ORM\Column(length: 20, enumType: FacesStatus::class)]
private FacesStatus $facesStatus = FacesStatus::Pending;

#[ORM\Column(length: 20, enumType: TagsStatus::class)]
private TagsStatus $tagsStatus = TagsStatus::Pending;

// getters/setters for each; remove getProcessingStatus/setProcessingStatus
```

Keep `processingError` accessors unchanged.

- [ ] **Step 2: Write migration with data backfill**

Create `apps/api/migrations/Version20260726180000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split photo.processing_status into media_status, faces_status, tags_status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE photo ADD media_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("ALTER TABLE photo ADD faces_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("ALTER TABLE photo ADD tags_status VARCHAR(20) DEFAULT 'pending' NOT NULL");

        $this->addSql("UPDATE photo SET media_status = 'pending', faces_status = 'pending', tags_status = 'pending' WHERE processing_status = 'pending'");
        $this->addSql("UPDATE photo SET media_status = 'converting', faces_status = 'pending', tags_status = 'pending' WHERE processing_status = 'converting'");
        $this->addSql("UPDATE photo SET media_status = 'done', faces_status = 'detecting', tags_status = 'detecting' WHERE processing_status = 'detecting'");
        $this->addSql("UPDATE photo SET media_status = 'done', faces_status = 'done', tags_status = 'done' WHERE processing_status = 'done'");
        $this->addSql("UPDATE photo SET media_status = 'failed', faces_status = 'pending', tags_status = 'pending' WHERE processing_status = 'failed' AND avif_path IS NULL");
        $this->addSql("UPDATE photo SET media_status = 'done', faces_status = 'failed', tags_status = 'pending' WHERE processing_status = 'failed' AND avif_path IS NOT NULL");

        $this->addSql('ALTER TABLE photo ALTER media_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo ALTER faces_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo ALTER tags_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo DROP processing_status');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE photo ADD processing_status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql("UPDATE photo SET processing_status = 'pending' WHERE media_status = 'pending'");
        $this->addSql("UPDATE photo SET processing_status = 'converting' WHERE media_status = 'converting'");
        $this->addSql("UPDATE photo SET processing_status = 'detecting' WHERE faces_status = 'detecting' OR tags_status = 'detecting'");
        $this->addSql("UPDATE photo SET processing_status = 'failed' WHERE media_status = 'failed' OR faces_status = 'failed' OR tags_status = 'failed'");
        $this->addSql("UPDATE photo SET processing_status = 'done' WHERE media_status = 'done' AND faces_status = 'done' AND tags_status = 'done'");
        $this->addSql('ALTER TABLE photo ALTER processing_status DROP DEFAULT');
        $this->addSql('ALTER TABLE photo DROP media_status');
        $this->addSql('ALTER TABLE photo DROP faces_status');
        $this->addSql('ALTER TABLE photo DROP tags_status');
    }
}
```

- [ ] **Step 3: Run migration**

```bash
docker compose exec -T api php bin/console doctrine:migrations:migrate --no-interaction
```

Expected: `Version20260726180000` migrated successfully.

- [ ] **Step 4: Commit**

```bash
git add apps/api/src/Entity/Photo.php apps/api/migrations/Version20260726180000.php
git commit -m "$(cat <<'EOF'
feat(api): split photo processing into media/faces/tags statuses

EOF
)"
```

---

### Task 3: PHP handlers, reprocessor, API normalize + PHPUnit

**Files:**
- Modify: `apps/api/src/MessageHandler/ConvertMediaHandler.php`
- Modify: `apps/api/src/Service/PhotoReprocessor.php`
- Modify: `apps/api/src/Controller/Api/Admin/PhotoController.php`
- Modify: `apps/api/src/Controller/Api/Admin/PhotoUploadController.php`
- Modify: `apps/api/tests/MessageHandler/ConvertMediaHandlerTest.php`
- Modify: `apps/api/tests/Api/PhotoUploadTest.php`
- Modify: `apps/api/tests/Api/PersonMergeTest.php` (only if assertions mention processing status)
- Delete: `apps/api/src/Enum/ProcessingStatus.php`

**Interfaces:**
- Consumes: Photo stage setters, `ProcessingErrorBag`, enums
- Produces API fields: `mediaStatus`, `facesStatus`, `tagsStatus`, `processingError` (no `processingStatus`)

- [ ] **Step 1: Update failing assertions in ConvertMediaHandlerTest + PhotoUploadTest**

In `ConvertMediaHandlerTest`, after successful convert assert:

```php
$this->assertSame('done', $photo->getMediaStatus()->value);
$this->assertSame('detecting', $photo->getFacesStatus()->value);
$this->assertSame('detecting', $photo->getTagsStatus()->value);
$this->assertNull($photo->getProcessingError());
```

On failure:

```php
$this->assertSame('failed', $photo->getMediaStatus()->value);
$this->assertSame('pending', $photo->getFacesStatus()->value);
$this->assertSame('pending', $photo->getTagsStatus()->value);
$this->assertSame('media: '.$e->getMessage() /* or assertStringStartsWith('media:', ...) */, $photo->getProcessingError());
```

Use `assertStringStartsWith('media:', $photo->getProcessingError())` if the exact exception message is awkward.

In `PhotoUploadTest`:

```php
$this->assertSame('pending', $data['mediaStatus']);
$this->assertSame('pending', $data['facesStatus']);
$this->assertSame('pending', $data['tagsStatus']);
$this->assertArrayNotHasKey('processingStatus', $data);
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T api php bin/phpunit tests/MessageHandler/ConvertMediaHandlerTest.php tests/Api/PhotoUploadTest.php
```

Expected: FAIL on missing methods / old field.

- [ ] **Step 3: Implement ConvertMediaHandler**

```php
use App\Enum\FacesStatus;
use App\Enum\MediaStatus;
use App\Enum\TagsStatus;
use App\Service\ProcessingErrorBag;

// start:
$photo->setMediaStatus(MediaStatus::Converting);
$this->em->flush();

// success:
$photo->setMediaStatus(MediaStatus::Done);
$photo->setFacesStatus(FacesStatus::Detecting);
$photo->setTagsStatus(TagsStatus::Detecting);
$photo->setProcessingError(
    ProcessingErrorBag::clear(
        ProcessingErrorBag::clear(
            ProcessingErrorBag::clear($photo->getProcessingError(), 'media'),
            'faces',
        ),
        'tags',
    ),
);
$this->em->flush();
$this->bus->dispatch(new DetectFacesMessage($photoId));
$this->bus->dispatch(new SuggestTagsMessage($photoId));

// failure:
$photo->setMediaStatus(MediaStatus::Failed);
$photo->setProcessingError(ProcessingErrorBag::set($photo->getProcessingError(), 'media', $e->getMessage()));
$this->em->flush();
```

- [ ] **Step 4: Implement PhotoReprocessor**

```php
public function reprocess(Photo $photo, string $scope = self::SCOPE_ALL): void
{
    $photoId = (string) $photo->getId();

    if (null === $photo->getAvifPath()) {
        $this->removeAutoDetectedFaces($photo);
        $photo->setMediaStatus(MediaStatus::Pending);
        $photo->setFacesStatus(FacesStatus::Pending);
        $photo->setTagsStatus(TagsStatus::Pending);
        $photo->setProcessingError(
            ProcessingErrorBag::clear(
                ProcessingErrorBag::clear(
                    ProcessingErrorBag::clear($photo->getProcessingError(), 'media'),
                    'faces',
                ),
                'tags',
            ),
        );
        $this->em->flush();
        $this->bus->dispatch(new ConvertMediaMessage($photoId));

        return;
    }

    if (self::SCOPE_TAGS !== $scope) {
        $this->removeAutoDetectedFaces($photo);
        $photo->setFacesStatus(FacesStatus::Detecting);
        $photo->setProcessingError(ProcessingErrorBag::clear($photo->getProcessingError(), 'faces'));
    }
    if (self::SCOPE_FACES !== $scope) {
        $photo->setTagsStatus(TagsStatus::Detecting);
        $photo->setProcessingError(ProcessingErrorBag::clear($photo->getProcessingError(), 'tags'));
    }
    $this->em->flush();

    if (self::SCOPE_TAGS !== $scope) {
        $this->bus->dispatch(new DetectFacesMessage($photoId));
    }
    if (self::SCOPE_FACES !== $scope) {
        $this->bus->dispatch(new SuggestTagsMessage($photoId));
    }
}
```

Update the class docblock to remove the old “tag worker does not own status” note.

- [ ] **Step 5: Update upload + normalize**

`PhotoUploadController::upload` — remove `setProcessingStatus`; entity defaults are pending (or explicitly set the three). In `normalize` for both controllers:

```php
'mediaStatus' => $photo->getMediaStatus()->value,
'facesStatus' => $photo->getFacesStatus()->value,
'tagsStatus' => $photo->getTagsStatus()->value,
'processingError' => $photo->getProcessingError(),
```

Remove `processingStatus` key.

- [ ] **Step 6: Delete `ProcessingStatus.php` and fix any remaining PHP references**

```bash
rg -n 'ProcessingStatus|processingStatus|getProcessingStatus|setProcessingStatus' apps/api
```

Expected: no matches (except historical migrations that mention the old column name in SQL strings — those stay).

- [ ] **Step 7: Run full PHPUnit**

```bash
docker compose exec -T api php bin/phpunit
```

Expected: OK (all green).

- [ ] **Step 8: Commit**

```bash
git add apps/api
git commit -m "$(cat <<'EOF'
feat(api): wire media/faces/tags statuses through convert and reprocess

EOF
)"
```

---

### Task 4: worker-faces writes `faces_status`

**Files:**
- Modify: `apps/worker-faces/db.py`
- Modify: `apps/worker-faces/main.py`
- Create: `apps/worker-faces/tests/test_processing_error.py`

**Interfaces:**
- Produces: `db.set_faces_status(conn, photo_id, status: str, error: str | None = None)`  
  - On success (`done`): clear `faces:` line, keep other lines  
  - On failure: set `faces:` line  
  - SQL updates `faces_status` and `processing_error` only
- Consumes: `photo` columns from Task 2 migration

- [ ] **Step 1: Write failing unit tests for error bag helpers**

Create `apps/worker-faces/tests/test_processing_error.py` testing pure functions you’ll add at the top of `db.py` (or a small `processing_error.py`):

```python
from db import clear_stage_error, set_stage_error

def test_set_stage_error_adds_line():
    assert set_stage_error(None, "faces", "boom") == "faces: boom"

def test_set_replaces_and_keeps_other_stages():
    current = "media: disk\nfaces: old"
    assert set_stage_error(current, "faces", "new") == "media: disk\nfaces: new"

def test_clear_stage_error():
    assert clear_stage_error("media: disk\nfaces: boom", "faces") == "media: disk"
    assert clear_stage_error("faces: boom", "faces") is None
```

- [ ] **Step 2: Run to verify fail**

```bash
docker compose exec -T worker-faces python -m pytest tests/test_processing_error.py -v
```

Expected: FAIL (import/attribute errors). If the container has no pytest, run via:

```bash
docker compose run --rm --entrypoint python worker-faces -m pytest tests/test_processing_error.py -v
```

or from the host with `PYTHONPATH=apps/worker-faces` if that matches existing worker-faces test practice — check how other worker-faces tests are run and use the same command.

- [ ] **Step 3: Implement helpers + `set_faces_status`**

In `db.py`, replace `set_photo_status` with:

```python
STAGES = ("media", "faces", "tags")

def set_stage_error(current: Optional[str], stage: str, message: str) -> str:
    ...

def clear_stage_error(current: Optional[str], stage: str) -> Optional[str]:
    ...

def get_processing_error(conn, photo_id: str) -> Optional[str]:
    with conn.cursor() as cur:
        cur.execute("SELECT processing_error FROM photo WHERE id = %s", (photo_id,))
        row = cur.fetchone()
        return None if row is None else row[0]

def set_faces_status(
    conn: psycopg.Connection,
    photo_id: str,
    status: str,
    error: Optional[str] = None,
) -> None:
    current = get_processing_error(conn, photo_id)
    if status == "done":
        new_error = clear_stage_error(current, "faces")
    else:
        new_error = set_stage_error(current, "faces", error or "unknown error")
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE photo SET faces_status = %s, processing_error = %s WHERE id = %s",
            (status, new_error, photo_id),
        )
```

Mirror the PHP line-merge rules (stage-prefixed lines + preserve legacy unprefixed lines).

- [ ] **Step 4: Update main.py call sites**

```python
db.set_faces_status(conn, photo_id, "done")
# on failure:
db.set_faces_status(conn, photo_id, "failed", error=str(e))
```

Update the module docstring to say it owns `faces_status`, not `processing_status`.

- [ ] **Step 5: Run worker-faces tests**

Use the same pytest invocation as Step 2 for the full `tests/` folder.

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/worker-faces
git commit -m "$(cat <<'EOF'
feat(worker-faces): write faces_status and prefixed errors

EOF
)"
```

---

### Task 5: worker-tags writes `tags_status`

**Files:**
- Modify: `apps/worker-tags/db.py`
- Modify: `apps/worker-tags/main.py`
- Create: `apps/worker-tags/tests/test_processing_error.py`

**Interfaces:**
- Produces: `db.set_tags_status(conn, photo_id, status, error=None)` analogous to faces
- On `handle_message` success → `tags_status=done`; failure → `failed` + `tags:` line
- Optionally set `detecting` is already done by PHP before enqueue — worker only writes terminal states

- [ ] **Step 1: Copy/adapt the same error-bag unit tests under worker-tags**

Same assertions as Task 4, importing from worker-tags `db` (or shared local helper module in that package).

- [ ] **Step 2: Run to verify fail**

```bash
docker compose exec -T worker-tags python -m pytest tests/test_processing_error.py -v
```

(or the project’s established pytest entrypoint for worker-tags)

- [ ] **Step 3: Implement `set_tags_status` + wire `handle_message`**

```python
def handle_message(conn, cfg: Config, payload: bytes) -> None:
    ...
    try:
        count = process_photo(conn, cfg, photo_id)
        db.set_tags_status(conn, photo_id, "done")
        conn.commit()
        log.info("photo %s: applied %d tag(s), tags_status=done", photo_id, count)
    except Exception as e:  # noqa: BLE001
        log.exception("suggest_tags failed for photo %s", photo_id)
        try:
            db.set_tags_status(conn, photo_id, "failed", error=str(e))
            conn.commit()
        except Exception:
            log.exception("also failed to record tags failure for photo %s", photo_id)
```

Ensure `process_photo` path commits tag attaches consistently with existing `db` transaction usage (match patterns in `worker-faces/main.py`).

Update module docstring: worker **does** update `tags_status`.

- [ ] **Step 4: Run worker-tags tests**

```bash
docker compose exec -T worker-tags python -m pytest tests/ -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/worker-tags
git commit -m "$(cat <<'EOF'
feat(worker-tags): write tags_status and prefixed errors

EOF
)"
```

---

### Task 6: Frontend types + album grid + photo detail

**Files:**
- Modify: `apps/web/src/api/types.ts`
- Modify: `apps/web/src/views/admin/AlbumPhotosView.vue`
- Modify: `apps/web/src/views/admin/AlbumPhotosView.spec.ts`
- Modify: `apps/web/src/views/admin/PhotoEditView.vue`
- Modify: `apps/web/src/views/admin/AlbumsView.spec.ts` (fixture only)
- Create: `apps/web/src/views/admin/PhotoEditView.spec.ts` only if one already exists or is needed — prefer extending existing patterns; if no PhotoEditView spec exists, add a focused one for the three badges

**Interfaces:**
- Types:
  - `MediaStatus = 'pending' | 'converting' | 'done' | 'failed'`
  - `FacesStatus = 'pending' | 'detecting' | 'done' | 'failed'`
  - `TagsStatus = 'pending' | 'detecting' | 'done' | 'failed'`
  - Remove `ProcessingStatus`
  - `AdminPhotoSummary` / `AdminPhotoDetail`: `mediaStatus`, `facesStatus`, `tagsStatus`, `processingError`
- UI: three badges; `data-testid="status-media"`, `status-faces`, `status-tags`

- [ ] **Step 1: Update types and failing Vitest fixtures**

In `types.ts` replace `ProcessingStatus` with the three types and update photo interfaces.

In `AlbumPhotosView.spec.ts` `makePhoto`:

```ts
mediaStatus: 'done',
facesStatus: 'done',
tagsStatus: 'done',
```

Update the detecting fixture photo to `facesStatus: 'detecting'` (media/tags `done`), and adjust badge text expectations to three badges per row.

Add assertion that reprocess-album still works and that badges include Faces/Tags labels.

- [ ] **Step 2: Run Vitest to see failures**

```bash
cd apps/web && npx vitest run src/views/admin/AlbumPhotosView.spec.ts
```

Expected: FAIL on missing fields / old badge text.

- [ ] **Step 3: Update AlbumPhotosView.vue**

```ts
import type { AdminPhotoSummary, FacesStatus, MediaStatus, ReprocessScope, TagsStatus } from '../../api/types'

function isInFlight(p: AdminPhotoSummary): boolean {
  return (
    p.mediaStatus === 'pending' ||
    p.mediaStatus === 'converting' ||
    p.facesStatus === 'detecting' ||
    p.tagsStatus === 'detecting'
  )
}

const inFlight = computed(() => photos.value.some(isInFlight))

const MEDIA_LABEL: Record<MediaStatus, string> = {
  pending: 'Media pending',
  converting: 'Converting',
  done: 'Media done',
  failed: 'Media failed',
}
// similar FACE_LABEL / TAG_LABEL with short "Faces …" / "Tags …"

function badgeVariantFor(status: string) {
  if (status === 'done') return 'default'
  if (status === 'failed') return 'destructive'
  if (status === 'pending') return 'secondary'
  return 'outline'
}
```

When merging reprocess responses, copy the three status fields + `processingError`.

Template badges:

```vue
<div class="mt-1.5 flex flex-wrap gap-1">
  <Badge data-testid="status-media" :variant="badgeVariantFor(photo.mediaStatus)" class="text-[10px]">
    {{ MEDIA_LABEL[photo.mediaStatus] }}
  </Badge>
  <Badge data-testid="status-faces" :variant="badgeVariantFor(photo.facesStatus)" class="text-[10px]">
    {{ FACE_LABEL[photo.facesStatus] }}
  </Badge>
  <Badge data-testid="status-tags" :variant="badgeVariantFor(photo.tagsStatus)" class="text-[10px]">
    {{ TAG_LABEL[photo.tagsStatus] }}
  </Badge>
</div>
<p v-if="photo.processingError" class="line-clamp-2 text-xs text-destructive">
  {{ photo.processingError }}
</p>
```

Show `processingError` whenever present (any stage), not only when a single legacy failed status.

- [ ] **Step 4: Update PhotoEditView.vue header**

Replace the single badge with the same three badges + optional error alert/paragraph bound to `photo.processingError`.

- [ ] **Step 5: Fix AlbumsView.spec.ts fixtures** if they embed photo objects with `processingStatus`.

- [ ] **Step 6: Run Vitest for affected specs**

```bash
cd apps/web && npx vitest run src/views/admin/AlbumPhotosView.spec.ts src/views/admin/AlbumsView.spec.ts
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add apps/web
git commit -m "$(cat <<'EOF'
feat(web): show separate media/faces/tags processing badges

EOF
)"
```

- [ ] **Step 8: Refresh graphify**

```bash
graphify update .
```

---

### Task 7: End-to-end verification

**Files:** none new

- [ ] **Step 1: Full API tests**

```bash
docker compose exec -T api php bin/phpunit
```

Expected: OK.

- [ ] **Step 2: Worker tests**

Run faces + tags pytest suites with the same commands used in Tasks 4–5.

Expected: PASS.

- [ ] **Step 3: Manual smoke (optional but recommended)**

1. Open an album in admin — confirm three badges per photo  
2. Reprocess album with “Tags only” — Tags badge → detecting → done; Faces unchanged  
3. Open photo detail — three badges + any prefixed error text  

- [ ] **Step 4: Final commit only if verification produced fixes**

Otherwise done.

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| Three DB columns; drop `processing_status` | 2 |
| Migration mapping table | 2 |
| Prefixed `processing_error` bag | 1, 4, 5 |
| Upload / convert lifecycle | 3 |
| Reprocess scopes set stage statuses | 3 |
| API payload fields | 3 |
| worker-faces owns faces | 4 |
| worker-tags owns tags | 5 |
| Album grid three badges + polling rule | 6 |
| Photo detail three badges + error | 6 |
| PHPUnit / pytest / Vitest | 1, 3–7 |

## Placeholder / consistency notes

- Stage string literals are always `media` / `faces` / `tags`.
- Status value strings match enum `value` properties.
- API JSON uses camelCase `mediaStatus` / `facesStatus` / `tagsStatus`.
- No leftover `ProcessingStatus` / `processingStatus` after Task 3 + 6.
