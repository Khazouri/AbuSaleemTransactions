/**
 * Flatten the API's flat department list into display order, carrying a depth
 * for indentation. Shared by the Departments screen and the Users screen's
 * hierarchy view, so both draw the same tree.
 *
 * Walks from the roots down, so each department appears directly beneath its
 * parent. Built iteratively with an explicit stack rather than recursion —
 * and note that any department whose parent is missing is treated as a root,
 * so a broken parent link can never hide a row from the screen entirely.
 */
export function flattenDepartments(departments) {
  const byParent = new Map()
  for (const dept of departments) {
    const key = dept.parent_id ?? null
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key).push(dept)
  }

  const knownIds = new Set(departments.map((d) => d.id))
  // Roots: no parent, or a parent that isn't in the list (orphan safety net).
  const roots = departments.filter((d) => d.parent_id === null || !knownIds.has(d.parent_id))

  const rows = []
  // Reverse so that popping off the stack preserves the original order.
  const stack = roots.slice().reverse().map((d) => ({ dept: d, depth: 0 }))

  while (stack.length) {
    const { dept, depth } = stack.pop()
    rows.push({ ...dept, depth })

    const children = byParent.get(dept.id) ?? []
    for (let i = children.length - 1; i >= 0; i--) {
      stack.push({ dept: children[i], depth: depth + 1 })
    }
  }

  return rows
}
