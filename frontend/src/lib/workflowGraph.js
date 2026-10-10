/**
 * Workflow designer — slice 2. Turns a version payload (WorkflowVersionResource)
 * into the nodes and edges the canvas draws. Free of Vue so `node --test` can
 * check it.
 *
 * Layout, chosen so a 12-stage chain reads top to bottom without crossings:
 * - the stages some rule touches form the chain, one column, in order_no;
 * - a stage no rule touches (kept for history) goes to a side column, so it
 *   never looks like a step a file passes through;
 * - a rule that stays on its stage (cancel, defer, deadline_expired) is
 *   listed on the node — Vue Flow draws a self-loop as a dot on the node;
 * - rules between the same two stages share one edge;
 * - an edge to the next stage runs down the middle; one that skips stages
 *   runs down the start side; one that goes back runs up the end side, so
 *   neither is drawn across the chain. Each side edge gets its own offset so
 *   two never overlap.
 */
export const ROW_HEIGHT = 140
export const SIDE_X = 420
const OFFSET_BASE = 28
const OFFSET_STEP = 18
const HANDLES = {
  next: ['bottom', 'top'],
  skip: ['skip-out', 'skip-in'],
  back: ['back-out', 'back-in'],
}

export function buildGraph(version) {
  const stages = [...version.stages].sort((a, b) => a.order_no - b.order_no)
  const touched = new Set()
  for (const rule of version.transitions) {
    touched.add(rule.from_stage_id)
    touched.add(rule.to_stage_id)
  }

  const chain = stages.filter((stage) => touched.has(stage.id))
  const rank = new Map(chain.map((stage, index) => [stage.id, index]))
  const loops = new Map(stages.map((stage) => [stage.id, []]))
  const pairs = new Map()

  for (const rule of version.transitions) {
    if (rule.from_stage_id === rule.to_stage_id) {
      loops.get(rule.from_stage_id)?.push(rule)
      continue
    }
    const id = `${rule.from_stage_id}-${rule.to_stage_id}`
    if (!pairs.has(id)) pairs.set(id, { id, from: rule.from_stage_id, to: rule.to_stage_id, rules: [] })
    pairs.get(id).rules.push(rule)
  }

  let unusedRow = 0
  const nodes = stages.map((stage) => {
    const unused = !touched.has(stage.id)
    const position = unused ? { x: SIDE_X, y: unusedRow++ * ROW_HEIGHT } : { x: 0, y: rank.get(stage.id) * ROW_HEIGHT }
    return { id: String(stage.id), type: 'stage', position, data: { stage, loops: loops.get(stage.id), unused } }
  })

  const sideCount = { skip: 0, back: 0 }
  const edges = [...pairs.values()].map((pair) => {
    const step = rank.get(pair.to) - rank.get(pair.from)
    const route = step === 1 ? 'next' : step > 1 ? 'skip' : 'back'
    const [sourceHandle, targetHandle] = HANDLES[route]
    const offset = route === 'next' ? 0 : OFFSET_BASE + OFFSET_STEP * sideCount[route]++
    return {
      id: pair.id,
      source: String(pair.from),
      target: String(pair.to),
      sourceHandle,
      targetHandle,
      type: 'smoothstep',
      pathOptions: { offset },
      data: {
        rules: pair.rules,
        actions: pair.rules.map((rule) => rule.action),
        exception: pair.rules.every((rule) => rule.is_exception),
        route,
      },
    }
  })

  return { nodes, edges }
}
