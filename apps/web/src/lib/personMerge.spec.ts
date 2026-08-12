import { describe, expect, it } from 'vitest'
import { mergePair } from './personMerge'

describe('mergePair', () => {
  it('prefers the named person as survivor', () => {
    expect(
      mergePair(
        { id: 'a', isNamed: false, faceCount: 10 },
        { personId: 'b', isNamed: true, faceCount: 1 },
      ),
    ).toEqual({ sourceId: 'a', targetId: 'b' })
  })

  it('prefers more faces when naming is equal', () => {
    expect(
      mergePair(
        { id: 'a', isNamed: false, faceCount: 2 },
        { personId: 'b', isNamed: false, faceCount: 5 },
      ),
    ).toEqual({ sourceId: 'a', targetId: 'b' })
  })

  it('keeps the current person when naming and face counts do not favor the candidate', () => {
    expect(
      mergePair(
        { id: 'a', isNamed: true, faceCount: 5 },
        { personId: 'b', isNamed: true, faceCount: 5 },
      ),
    ).toEqual({ sourceId: 'b', targetId: 'a' })
  })
})
