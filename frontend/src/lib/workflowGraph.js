/**
 * Workflow designer — slice 2. Turns a version payload (WorkflowVersionResource)
 * into the nodes and edges the canvas draws. Free of Vue so `node --test` can
 * check it.
 *
 * Layout, chosen so a 12-stage chain reads top to bottom without crossings:
 * - the chain is the entry stage plus every stage some rule reaches from
 *   another stage, one column, in order_no;
 * - every other stage goes to a side column, so it never looks like a step
 *   a file passes through: one no rule touches at all (kept for history), or
 *   one nothing reaches whose rules only let files already parked there
 *   leave (a "drain", e.g. administrative_routing since the manager's
 *   one-click approve). An edge to or from the side column is `aside`;
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
  aside: ['skip-out', 'back-in'],
}

export function buildGraph(version) {
  const stages = [...version.stages].sort((a, b) => a.order_no - b.order_no)
  const touched = new Set()
  const reached = new Set()
  for (const rule of version.transitions) {
    touched.add(rule.from_stage_id)
    touched.add(rule.to_stage_id)
    if (rule.from_stage_id !== rule.to_stage_id) reached.add(rule.to_stage_id)
  }

  const entryId = stages[0]?.id
  const inChain = (stage) => stage.id === entryId || reached.has(stage.id)
  const chain = stages.filter(inChain)
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

  let asideRow = 0
  const nodes = stages.map((stage) => {
    const aside = !inChain(stage)
    const unused = !touched.has(stage.id)
    const position = aside ? { x: SIDE_X, y: asideRow++ * ROW_HEIGHT } : { x: 0, y: rank.get(stage.id) * ROW_HEIGHT }
    return {
      id: String(stage.id),
      type: 'stage',
      position,
      data: { stage, loops: loops.get(stage.id), aside, unused, drainOnly: aside && !unused },
    }
  })

  const sideCount = { skip: 0, back: 0, aside: 0 }
  const edges = [...pairs.values()].map((pair) => {
    const step = rank.get(pair.to) - rank.get(pair.from)
    let route = 'back'
    if (!rank.has(pair.from) || !rank.has(pair.to)) route = 'aside'
    else if (step === 1) route = 'next'
    else if (step > 1) route = 'skip'
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
