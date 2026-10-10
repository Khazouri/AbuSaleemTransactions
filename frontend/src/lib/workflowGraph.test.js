// Workflow designer — slice 2. Run: `node --test src/lib/workflowGraph.test.js` (from frontend/).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { buildGraph, ROW_HEIGHT, SIDE_X } from './workflowGraph.js'

const stage = (id, order_no) => ({ id, code: `s${id}`, order_no, name_ar: `م${id}`, name_en: `S${id}` })
const rule = (id, from, to, action, extra = {}) => ({ id, from_stage_id: from, to_stage_id: to, action, is_exception: false, ...extra })

const version = {
  stages: [stage(3, 3), stage(1, 1), stage(2, 2), stage(4, 4), stage(9, 9)],
  transitions: [
    rule(1, 1, 2, 'submit'),
    rule(2, 2, 3, 'forward'),
    rule(3, 2, 3, 'route_to_hr'),
    rule(4, 3, 1, 'return_to_employee', { is_exception: true }),
    rule(5, 1, 4, 'fast_track'),
    rule(6, 3, 3, 'cancel', { is_exception: true }),
    rule(7, 3, 4, 'register'),
  ],
}

test('stages a rule uses are stacked in order down one column', () => {
  const { nodes } = buildGraph(version)
  const chain = nodes.filter((node) => !node.data.unused)
  assert.deepEqual(chain.map((node) => node.id), ['1', '2', '3', '4'])
  chain.forEach((node, index) => assert.deepEqual(node.position, { x: 0, y: index * ROW_HEIGHT }))
})

test('unused stages go to the side column', () => {
  const { nodes } = buildGraph(version)
  const unused = nodes.find((node) => node.id === '9')
  assert.equal(unused.data.unused, true)
  assert.deepEqual(unused.position, { x: SIDE_X, y: 0 })
})

test('a rule that stays on its stage is listed on the node, not drawn', () => {
  const { nodes, edges } = buildGraph(version)
  assert.deepEqual(nodes.find((node) => node.id === '3').data.loops.map((r) => r.action), ['cancel'])
  assert.equal(edges.some((edge) => edge.source === edge.target), false)
})

test('parallel rules between the same two stages share one edge', () => {
  const edge = buildGraph(version).edges.find((e) => e.id === '2-3')
  assert.deepEqual(edge.data.actions, ['forward', 'route_to_hr'])
})

test('each edge leaves by the side that keeps it off the chain', () => {
  const byId = Object.fromEntries(buildGraph(version).edges.map((edge) => [edge.id, edge]))
  assert.equal(byId['1-2'].data.route, 'next')
  assert.equal(byId['1-2'].sourceHandle, 'bottom')
  assert.equal(byId['1-2'].targetHandle, 'top')
  assert.equal(byId['1-4'].data.route, 'skip')
  assert.equal(byId['1-4'].sourceHandle, 'skip-out')
  assert.equal(byId['3-1'].data.route, 'back')
  assert.equal(byId['3-1'].targetHandle, 'back-in')
})

test('an edge whose every rule is an exception is marked as one', () => {
  const byId = Object.fromEntries(buildGraph(version).edges.map((edge) => [edge.id, edge]))
  assert.equal(byId['3-1'].data.exception, true)
  assert.equal(byId['2-3'].data.exception, false)
})
