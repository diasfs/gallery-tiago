# Person List Pagination and Merge Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Paginate the admin people list, prevent Doctrine from hydrating every face during list normalization, and make every named person discoverable as a merge target through remote search.

**Architecture:** `GET /api/admin/people` will use the existing project pagination contract and fetch only one page of `Person` entities. Face counts and fallback avatar paths will come from one grouped scalar query for those person IDs, so list normalization never accesses `Person::getFaces()`. The Vue client will consume the paginated response, reuse `PaginationBar`, and share a request-sequenced remote person search composable between person merge and photo editing.

**Tech Stack:** PHP 8.2, Symfony 7.2, Doctrine ORM 3.6, PHPUnit 11, Vue 3, TypeScript, Vue Router, Vitest.

## Global Constraints

- The people list uses 50 rows per page.
- Picker searches request at most 20 named people.
- `scope`, `q`, and `page` remain URL-driven on the people page.
- List responses must not initialize `Person::faces`.
- Detail responses continue returning all faces for one person.
- Existing accent-insensitive matching through `SearchText::likePattern()` and `UNACCENT` remains unchanged.
- Do not execute commit steps unless the user explicitly authorizes commits.

---

### Task 1: Paginated, memory-bounded people API

**Files:**
- Modify: `apps/api/tests/Api/PersonMergeTest.php`
- Modify: `apps/api/src/Repository/PersonRepository.php`
- Modify: `apps/api/src/Controller/Api/Admin/PersonController.php`

**Interfaces:**
- Produces: `PersonRepository::searchPaginated(string $scope, ?string $query, int $page, int $perPage): array{items: Person[], total: int}`
- Produces: `PersonRepository::summarizeFacesForPersonIds(array $personIds): array<string, array{faceCount: int, fallbackCropPath: ?string}>`
- Produces: `GET /api/admin/people?scope=<scope>&q=<query>&page=<page>&perPage=<perPage>` returning `Paginated<AdminPerson>`

- [ ] **Step 1: Write failing API tests for pagination and safe summaries**

Add focused tests to `PersonMergeTest`:

```php
public function testAdminPeopleListIsPaginated(): void
{
    for ($index = 1; $index <= 25; ++$index) {
        $person = new Person();
        $person->setName(\sprintf('Person %02d', $index));
        $person->setIsNamed(true);
        $this->em->persist($person);
    }
    $this->em->flush();

    $this->loginAsAdmin();
    $this->client->request('GET', '/api/admin/people?scope=named&page=2&perPage=10');

    $this->assertResponseIsSuccessful();
    $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
    $this->assertCount(10, $payload['data']);
    $this->assertSame(25, $payload['meta']['total']);
    $this->assertSame(2, $payload['meta']['page']);
    $this->assertSame(10, $payload['meta']['perPage']);
    $this->assertSame('Person 11', $payload['data'][0]['name']);
}

public function testAdminPeopleSearchFindsPersonBeyondFirstTwenty(): void
{
    for ($index = 1; $index <= 25; ++$index) {
        $person = new Person();
        $person->setName(\sprintf('Person %02d', $index));
        $person->setIsNamed(true);
        $this->em->persist($person);
    }
    $this->em->flush();

    $this->loginAsAdmin();
    $this->client->request('GET', '/api/admin/people?scope=named&q=Person%2025&page=1&perPage=20');

    $this->assertResponseIsSuccessful();
    $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
    $this->assertSame(1, $payload['meta']['total']);
    $this->assertSame(['Person 25'], array_column($payload['data'], 'name'));
}

public function testAdminPeoplePerPageIsCappedAtOneHundred(): void
{
    $this->loginAsAdmin();
    $this->client->request('GET', '/api/admin/people?scope=all&perPage=999');

    $this->assertResponseIsSuccessful();
    $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
    $this->assertSame(100, $payload['meta']['perPage']);
}

public function testAdminPeopleListReturnsAggregatedFaceCountAndFallbackAvatar(): void
{
    $person = new Person();
    $person->setName('Face owner');
    $person->setIsNamed(true);
    $this->em->persist($person);
    $first = $this->detectedFace($this->publicPhoto, $person);
    $second = $this->detectedFace($this->privatePhoto, $person);
    $this->em->flush();

    $this->loginAsAdmin();
    $this->client->request('GET', '/api/admin/people?scope=named&q=Face%20owner');

    $this->assertResponseIsSuccessful();
    $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
    $this->assertSame(2, $payload['data'][0]['faceCount']);
    $this->assertContains(
        $payload['data'][0]['avatarCropPath'],
        [$first->getCropPath(), $second->getCropPath()],
    );
}

public function testPaginatedPeopleQueriesDoNotInitializeFaceCollections(): void
{
    $person = new Person();
    $person->setName('Bounded');
    $person->setIsNamed(true);
    $this->em->persist($person);
    $this->detectedFace($this->publicPhoto, $person);
    $this->em->flush();
    $this->em->clear();

    /** @var \App\Repository\PersonRepository $repository */
    $repository = $this->em->getRepository(Person::class);
    $result = $repository->searchPaginated('named', 'Bounded', 1, 50);
    $listed = $result['items'][0];
    $this->assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $listed->getFaces());
    $this->assertFalse($listed->getFaces()->isInitialized());

    $repository->summarizeFacesForPersonIds([(string) $listed->getId()]);
    $this->assertFalse($listed->getFaces()->isInitialized());
}
```

- [ ] **Step 2: Run the new tests and verify RED**

Run:

```bash
docker compose exec api php bin/phpunit tests/Api/PersonMergeTest.php --filter 'test(AdminPeople(ListIsPaginated|SearchFindsPersonBeyondFirstTwenty|PerPageIsCappedAtOneHundred|ListReturnsAggregatedFaceCountAndFallbackAvatar)|PaginatedPeopleQueriesDoNotInitializeFaceCollections)'
```

Expected: pagination tests fail because the response has no `meta` and the old named query is capped at 20.

- [ ] **Step 3: Implement repository pagination**

Replace the list-oriented `search()` implementation with a paginated method while retaining `searchNamed()` as a compatibility wrapper if another caller still needs an entity array:

```php
/**
 * @param 'all'|'named'|'unnamed' $scope
 *
 * @return array{items: Person[], total: int}
 */
public function searchPaginated(
    string $scope,
    ?string $query,
    int $page,
    int $perPage,
): array {
    $base = $this->createQueryBuilder('p');

    if ('named' === $scope) {
        $base->andWhere('p.isNamed = true');
    } elseif ('unnamed' === $scope) {
        $base->andWhere('p.isNamed = false');
    }

    if (null !== $query && '' !== trim($query)) {
        $base->andWhere('LOWER(UNACCENT(p.name)) LIKE :query')
            ->setParameter('query', SearchText::likePattern($query));
    }

    $total = (int) (clone $base)
        ->select('COUNT(p.id)')
        ->getQuery()
        ->getSingleScalarResult();

    $itemsQuery = (clone $base)
        ->leftJoin('p.avatarFace', 'af')
        ->addSelect('af');

    if ('named' === $scope) {
        $itemsQuery->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');
    } elseif ('unnamed' === $scope) {
        $itemsQuery->orderBy('p.id', 'ASC');
    } else {
        $itemsQuery->orderBy('p.isNamed', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');
    }

    $items = $itemsQuery
        ->setFirstResult(($page - 1) * $perPage)
        ->setMaxResults($perPage)
        ->getQuery()
        ->getResult();

    return ['items' => $items, 'total' => $total];
}

/** @return Person[] */
public function searchNamed(?string $query): array
{
    return $this->searchPaginated('named', $query, 1, 20)['items'];
}
```

- [ ] **Step 4: Implement one grouped face-summary query**

Add:

```php
/**
 * @param string[] $personIds
 *
 * @return array<string, array{faceCount: int, fallbackCropPath: ?string}>
 */
public function summarizeFacesForPersonIds(array $personIds): array
{
    if ([] === $personIds) {
        return [];
    }

    $rows = $this->getEntityManager()->createQueryBuilder()
        ->select('IDENTITY(f.person) AS personId')
        ->addSelect('COUNT(f.id) AS faceCount')
        ->addSelect('MIN(f.cropPath) AS fallbackCropPath')
        ->from(\App\Entity\Face::class, 'f')
        ->andWhere('f.person IN (:personIds)')
        ->setParameter('personIds', $personIds)
        ->groupBy('f.person')
        ->getQuery()
        ->getArrayResult();

    $summaries = [];
    foreach ($rows as $row) {
        $summaries[(string) $row['personId']] = [
            'faceCount' => (int) $row['faceCount'],
            'fallbackCropPath' => $row['fallbackCropPath'],
        ];
    }

    return $summaries;
}
```

This query returns scalars only; it must not select or hydrate `Face` entities.

- [ ] **Step 5: Return the paginated contract without touching face collections**

Import `App\Http\Pagination`, then change `PersonController::search()`:

```php
$page = Pagination::page($request);
$perPage = Pagination::perPage($request, 50, 100);
$result = $this->people->searchPaginated(
    $scope,
    \is_string($q) ? $q : null,
    $page,
    $perPage,
);
$ids = array_map(static fn (Person $person): string => (string) $person->getId(), $result['items']);
$faceSummaries = $this->people->summarizeFacesForPersonIds($ids);

$data = array_map(
    fn (Person $person): array => $this->normalizePerson(
        $person,
        $faceSummaries[(string) $person->getId()] ?? ['faceCount' => 0, 'fallbackCropPath' => null],
    ),
    $result['items'],
);

return new JsonResponse([
    'data' => $data,
    'meta' => Pagination::meta($page, $perPage, $result['total']),
]);
```

Change the normalizer to accept optional precomputed data:

```php
/**
 * @param array{faceCount: int, fallbackCropPath: ?string}|null $faceSummary
 *
 * @return array<string, mixed>
 */
private function normalizePerson(Person $person, ?array $faceSummary = null): array
{
    $avatar = $person->getAvatarFace();
    if (null !== $faceSummary) {
        $faceCount = $faceSummary['faceCount'];
        $avatarCropPath = $person->getAvatarPath()
            ?? $avatar?->getCropPath()
            ?? $faceSummary['fallbackCropPath'];
    } else {
        $faceCount = \count($person->getFaces());
        $avatarCropPath = $person->getEffectiveAvatarPath();
    }

    return [
        'id' => (string) $person->getId(),
        'name' => $person->getName(),
        'isNamed' => $person->isNamed(),
        'faceCount' => $faceCount,
        'avatarFaceId' => $avatar ? (string) $avatar->getId() : null,
        'avatarCropPath' => $avatarCropPath,
    ];
}
```

The list path always passes `$faceSummary`; detail, merge, and naming paths may retain the existing single-person fallback.

- [ ] **Step 6: Run API tests and verify GREEN**

Run:

```bash
docker compose exec api php bin/phpunit tests/Api/PersonMergeTest.php
```

Expected: all `PersonMergeTest` tests pass.

- [ ] **Step 7: Commit only if authorized**

```bash
git add apps/api/src/Repository/PersonRepository.php apps/api/src/Controller/Api/Admin/PersonController.php apps/api/tests/Api/PersonMergeTest.php
git commit -m "fix(api): paginate people without hydrating faces"
```

---

### Task 2: Paginated API client and people-list controls

**Files:**
- Modify: `apps/web/src/api/client.ts`
- Modify: `apps/web/src/views/admin/PeopleView.vue`
- Modify: `apps/web/src/views/admin/PeopleView.spec.ts`

**Interfaces:**
- Consumes: paginated `GET /api/admin/people` from Task 1
- Produces: `adminApi.listPeople(params?: {scope?: PeopleScope; q?: string; page?: number; perPage?: number}): Promise<Paginated<AdminPerson>>`

- [ ] **Step 1: Write failing people-page pagination tests**

Change the default mock to return:

```ts
mockedApi.listPeople.mockResolvedValue({
  data: [
    makePerson(),
    makePerson({
      id: 'cluster-1',
      name: null,
      isNamed: false,
      faceCount: 2,
      avatarFaceId: null,
      avatarCropPath: null,
    }),
  ],
  meta: { page: 1, perPage: 50, total: 75 },
})
```

Update the first expectation and add:

```ts
expect(mockedApi.listPeople).toHaveBeenCalledWith({
  scope: 'all',
  q: undefined,
  page: 1,
  perPage: 50,
})

it('navigates pages while preserving scope and search', async () => {
  const { wrapper, router } = await mountView({ scope: 'named', q: 'ana' })

  await wrapper.find('[data-testid="pagination"] button:last-child').trigger('click')
  await flushPromises()

  expect(router.currentRoute.value.query).toEqual({ scope: 'named', q: 'ana', page: '2' })
  expect(mockedApi.listPeople).toHaveBeenLastCalledWith({
    scope: 'named',
    q: 'ana',
    page: 2,
    perPage: 50,
  })
})

it('resets page when changing scope', async () => {
  const { wrapper, router } = await mountView({ page: '2', q: 'ana' })

  await wrapper.find('[data-testid="scope-unnamed"]').trigger('click')
  await flushPromises()

  expect(router.currentRoute.value.query).toEqual({ scope: 'unnamed', q: 'ana' })
})

it('resets page when submitting a search', async () => {
  const { wrapper, router } = await mountView({ page: '2' })

  await wrapper.find('[data-testid="people-search"]').setValue('grace')
  await wrapper.find('form').trigger('submit')
  await flushPromises()

  expect(router.currentRoute.value.query).toEqual({ q: 'grace' })
})

it('allows returning from an empty later page', async () => {
  mockedApi.listPeople.mockResolvedValue({
    data: [],
    meta: { page: 2, perPage: 50, total: 50 },
  })
  const { wrapper, router } = await mountView({ page: '2' })

  await wrapper.find('[data-testid="people-empty-previous"]').trigger('click')
  await flushPromises()

  expect(router.currentRoute.value.query.page).toBeUndefined()
})
```

- [ ] **Step 2: Run the view test and verify RED**

Run:

```bash
docker compose exec frontend bun run test -- src/views/admin/PeopleView.spec.ts
```

Expected: tests fail because `listPeople` still returns an array and `PeopleView` has no pagination.

- [ ] **Step 3: Change the client method to return a page**

Replace `listPeople` and remove the redundant admin `searchPeople` method:

```ts
listPeople: (
  params: {
    scope?: PeopleScope
    q?: string
    page?: number
    perPage?: number
  } = {},
) =>
  adminRequestRaw<Paginated<AdminPerson>>(
    `/api/admin/people${queryString({
      scope: params.scope ?? 'named',
      q: params.q,
      page: params.page,
      perPage: params.perPage,
    })}`,
  ),
```

Add `PeopleScope` to the existing type imports in `client.ts`.

- [ ] **Step 4: Add URL-driven pagination to `PeopleView`**

Import `PaginationBar`, define:

```ts
const perPage = 50
const total = ref(0)
const page = computed(() => Math.max(1, Number(route.query.page) || 1))
```

Update `load()`:

```ts
const result = await adminApi.listPeople({
  scope: scope.value,
  q: search.value.trim() || undefined,
  page: page.value,
  perPage,
})
people.value = result.data
total.value = result.meta.total
```

Watch `scope`, `route.query.q`, and `page` once. Add:

```ts
function setPage(nextPage: number) {
  router.push({
    name: 'admin-people',
    query: {
      ...(scope.value !== 'all' ? { scope: scope.value } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(nextPage > 1 ? { page: String(nextPage) } : {}),
    },
  })
}
```

Keep `page` out of `setScope()` and `submitSearch()` so both reset to page 1. Render after the table:

```vue
<PaginationBar
  :page="page"
  :total="total"
  :per-page="perPage"
  @update:page="setPage"
/>
```

Inside the existing empty state, add the explicit escape from a now-empty later page:

```vue
<Button
  v-if="page > 1"
  type="button"
  variant="outline"
  size="sm"
  data-testid="people-empty-previous"
  @click="setPage(page - 1)"
>
  Voltar à página anterior
</Button>
```

- [ ] **Step 5: Run the people-page tests and verify GREEN**

Run:

```bash
docker compose exec frontend bun run test -- src/views/admin/PeopleView.spec.ts
```

Expected: all `PeopleView` tests pass.

- [ ] **Step 6: Commit only if authorized**

```bash
git add apps/web/src/api/client.ts apps/web/src/views/admin/PeopleView.vue apps/web/src/views/admin/PeopleView.spec.ts
git commit -m "feat(web): paginate admin people list"
```

---

### Task 3: Race-safe remote person search

**Files:**
- Create: `apps/web/src/composables/useAdminPersonSearch.ts`
- Create: `apps/web/src/composables/useAdminPersonSearch.spec.ts`
- Modify: `apps/web/src/views/admin/PersonEditView.vue`
- Modify: `apps/web/src/views/admin/PersonEditView.spec.ts`

**Interfaces:**
- Consumes: `adminApi.listPeople()` from Task 2
- Produces: `useAdminPersonSearch(excludeId?: () => string | undefined)` with `query`, `results`, `loading`, `error`, `search()`, and `clear()`

- [ ] **Step 1: Write failing composable tests**

Create `useAdminPersonSearch.spec.ts`:

```ts
import { describe, expect, it, vi } from 'vitest'
import { adminApi } from '../api/client'
import { useAdminPersonSearch } from './useAdminPersonSearch'

vi.mock('../api/client', async () => {
  const actual = await vi.importActual<typeof import('../api/client')>('../api/client')
  return { ...actual, adminApi: { listPeople: vi.fn() } }
})

const mockedListPeople = adminApi.listPeople as ReturnType<typeof vi.fn>

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((next) => { resolve = next })
  return { promise, resolve }
}

describe('useAdminPersonSearch', () => {
  it('requests at most twenty named people and excludes the current person', async () => {
    mockedListPeople.mockResolvedValue({
      data: [
        { id: 'self', name: 'Ana', isNamed: true, faceCount: 1, avatarFaceId: null, avatarCropPath: null },
        { id: 'target', name: 'Ana Maria', isNamed: true, faceCount: 1, avatarFaceId: null, avatarCropPath: null },
      ],
      meta: { page: 1, perPage: 20, total: 2 },
    })
    const search = useAdminPersonSearch(() => 'self')
    search.query.value = 'ana'

    await search.search()

    expect(mockedListPeople).toHaveBeenCalledWith({ scope: 'named', q: 'ana', page: 1, perPage: 20 })
    expect(search.results.value.map((person) => person.id)).toEqual(['target'])
  })

  it('ignores a stale response', async () => {
    const first = deferred<any>()
    const second = deferred<any>()
    mockedListPeople.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
    const search = useAdminPersonSearch()

    search.query.value = 'a'
    const firstRequest = search.search()
    search.query.value = 'ab'
    const secondRequest = search.search()
    second.resolve({
      data: [{ id: 'new', name: 'Abel', isNamed: true, faceCount: 1, avatarFaceId: null, avatarCropPath: null }],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    await secondRequest
    first.resolve({
      data: [{ id: 'old', name: 'Ana', isNamed: true, faceCount: 1, avatarFaceId: null, avatarCropPath: null }],
      meta: { page: 1, perPage: 20, total: 1 },
    })
    await firstRequest

    expect(search.results.value[0]?.id).toBe('new')
  })

  it('exposes a retryable error without discarding the query', async () => {
    mockedListPeople.mockRejectedValue(new Error('offline'))
    const search = useAdminPersonSearch()
    search.query.value = 'Ana'

    await search.search()

    expect(search.query.value).toBe('Ana')
    expect(search.results.value).toEqual([])
    expect(search.error.value).toBe('Falha ao buscar pessoas.')
  })
})
```

- [ ] **Step 2: Run composable tests and verify RED**

Run:

```bash
docker compose exec frontend bun run test -- src/composables/useAdminPersonSearch.spec.ts
```

Expected: FAIL because `useAdminPersonSearch.ts` does not exist.

- [ ] **Step 3: Implement the composable**

Create:

```ts
import { ref } from 'vue'
import { adminApi } from '../api/client'
import type { AdminPerson } from '../api/types'

export function useAdminPersonSearch(excludeId?: () => string | undefined) {
  const query = ref('')
  const results = ref<AdminPerson[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0

  async function search() {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const response = await adminApi.listPeople({
        scope: 'named',
        q: query.value.trim() || undefined,
        page: 1,
        perPage: 20,
      })
      if (request !== latestRequest) return
      const excluded = excludeId?.()
      results.value = response.data.filter((person) => person.id !== excluded)
    } catch {
      if (request !== latestRequest) return
      results.value = []
      error.value = 'Falha ao buscar pessoas.'
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  function clear() {
    ++latestRequest
    query.value = ''
    results.value = []
    error.value = null
    loading.value = false
  }

  return { query, results, loading, error, search, clear }
}
```

- [ ] **Step 4: Write a failing merge-search view test**

Update the API mock and its local type:

```ts
adminApi: {
  getPerson: vi.fn(),
  listPeople: vi.fn(),
  updatePerson: vi.fn(),
  uploadPersonAvatar: vi.fn(),
  deletePersonAvatar: vi.fn(),
  mergePerson: vi.fn(),
  discardPerson: vi.fn(),
},
```

```ts
listPeople: ReturnType<typeof vi.fn>
```

Use this default in `beforeEach()`:

```ts
mockedApi.listPeople.mockResolvedValue({
  data: [],
  meta: { page: 1, perPage: 20, total: 0 },
})
```

Then add:

```ts
it('searches named merge targets remotely', async () => {
  mockedApi.listPeople.mockResolvedValue({
    data: [makeNamed({ id: 'person-25', name: 'Target 25' })],
    meta: { page: 1, perPage: 20, total: 1 },
  })
  const { wrapper } = await mountView()

  await wrapper.find('[data-testid="merge-search"]').setValue('Target 25')
  await wrapper.find('[data-testid="merge-search"]').trigger('input')
  await flushPromises()

  expect(mockedApi.listPeople).toHaveBeenCalledWith({
    scope: 'named',
    q: 'Target 25',
    page: 1,
    perPage: 20,
  })
  expect(wrapper.find('[data-testid="merge-target"]').exists()).toBe(true)

  testId('merge-target').click()
  await flushPromises()
  const option = [...document.querySelectorAll<HTMLElement>('[role="option"]')]
    .find((element) => element.textContent?.includes('Target 25'))
  if (!option) throw new Error('Missing Target 25 option')
  option.click()
  testId('merge-submit').click()
  await flushPromises()

  expect(mockedApi.mergePerson).toHaveBeenCalledWith('person-1', 'person-25')
})
```

- [ ] **Step 5: Replace eager merge options with remote search**

In `PersonEditView.vue`, remove `namedPeople` and the `adminApi.searchPeople()` call from `load()`. Destructure the composable so template ref unwrapping is explicit:

```ts
const {
  query: mergeQuery,
  results: mergeResults,
  loading: mergeLoading,
  error: mergeSearchError,
  search: searchMergeTargets,
} = useAdminPersonSearch(() => props.id)
```

Render an input and result select:

```vue
<div class="space-y-2 border-t border-border/60 pt-4">
  <Label for="merge-search">Mesclar com outra pessoa</Label>
  <Input
    id="merge-search"
    v-model="mergeQuery"
    type="search"
    placeholder="Buscar pessoa nomeada…"
    :disabled="saving || mergeLoading"
    data-testid="merge-search"
    @input="searchMergeTargets"
  />
  <p v-if="mergeSearchError" class="text-sm text-destructive">
    {{ mergeSearchError }}
  </p>
  <div v-if="mergeResults.length > 0" class="flex gap-2">
    <Select
      :model-value="form.mergeTargetId"
      :disabled="saving"
      @update:model-value="(value) => (form.mergeTargetId = String(value ?? ''))"
    >
      <SelectTrigger class="max-w-md min-w-0 flex-1" data-testid="merge-target">
        <SelectValue placeholder="Escolha uma pessoa…" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem
          v-for="candidate in mergeResults"
          :key="candidate.id"
          :value="candidate.id"
        >
          {{ candidate.name }}
        </SelectItem>
      </SelectContent>
    </Select>
    <Button type="button" variant="outline" size="sm" :disabled="saving" data-testid="merge-submit" @click="mergeInto">
      Mesclar
    </Button>
  </div>
</div>
```

- [ ] **Step 6: Run composable and person-edit tests**

Run:

```bash
docker compose exec frontend bun run test -- src/composables/useAdminPersonSearch.spec.ts src/views/admin/PersonEditView.spec.ts
```

Expected: all tests pass.

- [ ] **Step 7: Commit only if authorized**

```bash
git add apps/web/src/composables/useAdminPersonSearch.ts apps/web/src/composables/useAdminPersonSearch.spec.ts apps/web/src/views/admin/PersonEditView.vue apps/web/src/views/admin/PersonEditView.spec.ts
git commit -m "fix(web): search all named merge targets"
```

---

### Task 4: Reuse remote search in photo editing and verify the feature

**Files:**
- Modify: `apps/web/src/views/admin/PhotoEditView.vue`
- Modify: `apps/web/src/views/admin/PhotoEditView.spec.ts`
- Update generated graph: `graphify-out/`

**Interfaces:**
- Consumes: `useAdminPersonSearch()` from Task 3
- Produces: photo-person lookup using the paginated people API without a separate array-returning endpoint

- [ ] **Step 1: Update the photo-edit test mock and expectation**

Change the mocked API property and local mock type from `searchPeople` to `listPeople`. In `mountView()`, use:

```ts
mockedApi.listPeople.mockResolvedValue({
  data: [],
  meta: { page: 1, perPage: 20, total: 0 },
})
```

Add a focused test:

```ts
it('searches existing people through the paginated endpoint', async () => {
  mockedApi.listPeople.mockResolvedValue({
  data: [{
    id: 'person-2',
    name: 'Grace Hopper',
    isNamed: true,
    faceCount: 1,
    avatarFaceId: null,
    avatarCropPath: null,
  }],
  meta: { page: 1, perPage: 20, total: 1 },
  })
  const wrapper = await mountView(makePhoto())

  await wrapper.find('[data-testid="people-search"]').setValue('Grace')
  await wrapper.find('[data-testid="people-search"]').trigger('input')
  await flushPromises()

  expect(mockedApi.listPeople).toHaveBeenCalledWith({
    scope: 'named',
    q: 'Grace',
    page: 1,
    perPage: 20,
  })
})
```

- [ ] **Step 2: Run the photo-edit tests and verify RED**

Run:

```bash
docker compose exec frontend bun run test -- src/views/admin/PhotoEditView.spec.ts
```

Expected: FAIL because the component still invokes the removed `adminApi.searchPeople`.

- [ ] **Step 3: Replace local search state with the composable**

Replace `peopleQuery`, `peopleResults`, and `searchPeople()` with destructured refs:

```ts
const {
  query: peopleQuery,
  results: peopleResults,
  loading: peopleSearchLoading,
  search: searchPeople,
  clear: clearPeopleSearch,
} = useAdminPersonSearch()
```

After adding or creating a person, replace:

```ts
peopleQuery.value = ''
peopleResults.value = []
```

with:

```ts
clearPeopleSearch()
```

Keep `peopleBusy` for add/remove mutations and change the search input binding to:

```vue
:disabled="peopleBusy || peopleSearchLoading"
```

- [ ] **Step 4: Run all focused frontend tests**

Run:

```bash
docker compose exec frontend bun run test -- src/views/admin/PeopleView.spec.ts src/views/admin/PersonEditView.spec.ts src/views/admin/PhotoEditView.spec.ts src/composables/useAdminPersonSearch.spec.ts
```

Expected: all focused frontend tests pass.

- [ ] **Step 5: Run complete API and frontend verification**

Run:

```bash
docker compose exec api php bin/phpunit
docker compose exec frontend bun run test
docker compose exec frontend bun run build
```

Expected: PHPUnit, Vitest, TypeScript, and Vite build all complete successfully.

- [ ] **Step 6: Refresh the code knowledge graph**

Run:

```bash
graphify update .
```

Expected: graph update completes without errors and includes the changed PHP, Vue, TypeScript, and test files.

- [ ] **Step 7: Commit only if authorized**

```bash
git add apps/web/src/views/admin/PhotoEditView.vue apps/web/src/views/admin/PhotoEditView.spec.ts graphify-out
git commit -m "refactor(web): reuse paginated person search"
```
