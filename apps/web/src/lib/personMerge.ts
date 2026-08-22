/** Minimal fields needed to pick merge survivor (named > more faces > keep current). */
export type MergePersonSide = {
  id: string
  isNamed: boolean
  faceCount: number
}

export type MergeCandidateSide = {
  personId: string
  isNamed: boolean
  faceCount: number
}

export type PersonMergedPayload = {
  survivorId: string
  removedId: string
  survivorName: string | null
  survivorAvatarCropPath: string | null
}

type MergeParty = {
  id: string
  isNamed: boolean
  faceCount: number
}

function betterSurvivor(currentId: string, a: MergeParty, b: MergeParty): MergeParty {
  if (a.isNamed !== b.isNamed) return a.isNamed ? a : b
  if (a.faceCount !== b.faceCount) return a.faceCount > b.faceCount ? a : b
  if (a.id === currentId) return a
  if (b.id === currentId) return b
  return a
}

/** Pick survivor when merging current person with one or more candidates (same rules as mergePair). */
export function pickMergeSurvivor(
  current: MergePersonSide,
  candidates: MergeCandidateSide[],
): string {
  const parties: MergeParty[] = [
    { id: current.id, isNamed: current.isNamed, faceCount: current.faceCount },
    ...candidates.map((c) => ({
      id: c.personId,
      isNamed: c.isNamed,
      faceCount: c.faceCount,
    })),
  ]
  return parties.reduce((best, party) => betterSurvivor(current.id, best, party)).id
}

export function mergePair(
  current: MergePersonSide,
  candidate: MergeCandidateSide,
): { sourceId: string; targetId: string } {
  if (current.isNamed !== candidate.isNamed) {
    return current.isNamed
      ? { sourceId: candidate.personId, targetId: current.id }
      : { sourceId: current.id, targetId: candidate.personId }
  }
  if (candidate.faceCount > current.faceCount) {
    return { sourceId: current.id, targetId: candidate.personId }
  }
  return { sourceId: candidate.personId, targetId: current.id }
}
