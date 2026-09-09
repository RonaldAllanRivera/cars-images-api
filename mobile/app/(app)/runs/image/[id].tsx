/**
 * The same image detail screen, mounted inside the runs stack.
 *
 * Each tab owns its own stack, so linking a run's images at the search tab's
 * copy would switch tabs mid-flow and leave the back gesture returning to the
 * search form instead of the run. The screen reads its `id` from
 * useLocalSearchParams, so it works unchanged under this path - a re-export
 * rather than a second implementation.
 *
 * Named `runs/image/[id]` rather than nested under `runs/[id]` because both
 * segments would otherwise be called `id` and collide.
 */
export { default } from '../../search/[id]';
