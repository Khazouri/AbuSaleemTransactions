// Learning centre — bulk upload of walkthrough videos.
/**
 * Pairs picked files with catalogue rows by name: the recording toolkit writes
 * each clip as `<code>.mp4`, so the name is the only key needed. Case is folded
 * because Windows renames freely and a code is always lowercase. A second file
 * for a code is skipped rather than sent, so one run never uploads a row twice.
 */
export function matchVideoFiles(files, catalogue) {
  const byCode = new Map(catalogue.map((video) => [video.code.toLowerCase(), video]))
  const taken = new Set()
  const matched = []
  const skipped = []

  for (const file of files) {
    const code = file.name.toLowerCase().match(/^(.+)\.mp4$/)?.[1]
    const video = code && !taken.has(code) ? byCode.get(code) : null
    if (video) {
      taken.add(code)
      matched.push({ file, video })
    } else {
      skipped.push(file)
    }
  }

  return { matched, skipped }
}
