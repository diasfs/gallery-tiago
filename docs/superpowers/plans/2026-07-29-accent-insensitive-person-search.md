# Accent-Insensitive Person Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make public and administrative person suggestions match names regardless of accents.

**Architecture:** Keep filtering in `PersonRepository`, where result visibility and limits are already enforced. Apply the project's existing PostgreSQL `UNACCENT` DQL function to stored names and `SearchText::likePattern()` to user input.

**Tech Stack:** PHP 8, Symfony, Doctrine ORM, PostgreSQL `unaccent`, PHPUnit.

## Global Constraints

- `fabio` must find a person named `Fábio` in both public and administrative APIs.
- Public suggestions must continue excluding people who only occur in private or unlisted albums.
- `findOneNamedByName()` must retain its exact, case-insensitive behavior.
- Do not add dependencies or database migrations.

---

### Task 1: Accent-insensitive person suggestions

**Files:**
- Modify: `apps/api/tests/Api/PublicSearchTest.php`
- Modify: `apps/api/tests/Api/PersonMergeTest.php`
- Modify: `apps/api/src/Repository/PersonRepository.php`

**Interfaces:**
- Consumes: `App\Service\SearchText::likePattern(string $query): string` and the configured DQL `UNACCENT()` function.
- Produces: unchanged `PersonRepository::search()` and `PersonRepository::searchNamedPublic()` signatures with accent-insensitive matching.

- [ ] **Step 1: Write failing public API test**

Rename the public fixture person to `Fábio Silva`, query `/api/people?q=fabio`, and assert that `Fábio Silva` is returned while preserving the private-only assertion.

```php
$this->namedPerson->setName('Fábio Silva');

$this->client->request('GET', '/api/people?q=fabio');
$this->assertResponseIsSuccessful();
$people = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
$this->assertContains('Fábio Silva', array_column($people, 'name'));
```

- [ ] **Step 2: Write failing administrative API test**

Add a named `Fábio` person, authenticate, query `/api/admin/people?q=fabio`, and assert that the response includes `Fábio`.

```php
public function testAdminPeopleSearchIgnoresAccents(): void
{
    $person = new Person();
    $person->setName('Fábio');
    $person->setIsNamed(true);
    $this->em->persist($person);
    $this->em->flush();

    $this->loginAsAdmin();
    $this->client->request('GET', '/api/admin/people?q=fabio');

    $this->assertResponseIsSuccessful();
    $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
    $this->assertContains('Fábio', array_column($data, 'name'));
}
```

- [ ] **Step 3: Run focused tests and verify RED**

Run:

```bash
docker compose exec api php bin/phpunit tests/Api/PublicSearchTest.php tests/Api/PersonMergeTest.php
```

Expected: both new accent-insensitive assertions fail because `LOWER(name) LIKE '%fabio%'` does not match `fábio`.

- [ ] **Step 4: Implement repository normalization**

Import `SearchText` and update only the two search predicates:

```php
use App\Service\SearchText;

$qb->andWhere('LOWER(UNACCENT(p.name)) LIKE :query')
    ->setParameter('query', SearchText::likePattern($query));

$qb->andWhere('LOWER(UNACCENT(person.name)) LIKE :query')
    ->setParameter('query', SearchText::likePattern($query));
```

- [ ] **Step 5: Run focused tests and verify GREEN**

Run:

```bash
docker compose exec api php bin/phpunit tests/Api/PublicSearchTest.php tests/Api/PersonMergeTest.php
```

Expected: PASS.

- [ ] **Step 6: Run the full API test suite**

Run:

```bash
docker compose exec api php bin/phpunit
```

Expected: PASS with no regressions.

- [ ] **Step 7: Refresh code graph**

Run:

```bash
graphify update .
```

Expected: incremental graph update completes successfully.
