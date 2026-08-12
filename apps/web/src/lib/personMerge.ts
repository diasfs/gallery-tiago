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
