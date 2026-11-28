/**
 * A stand-in for `@wordpress/interactivity` in the storefront modules' tests: just enough of its
 * runtime to run a store's getters, actions and callbacks without WordPress.
 *
 * `store()` keeps each store's definition and returns it; a generator action runs as WordPress
 * runs one, to the end, each yielded promise awaited. A test sets the context an action sees
 * (`runtime.context`) and the configuration of each store (`runtime.config`).
 */

const runtime = {
	stores: {},
	context: {},
	config: {},
};

/**
 * Runs a generator action to its end, as WordPress does, awaiting each promise it yields.
 *
 * @param {Function} action The generator function.
 * @return {Function} An async function.
 */
function running( action ) {
	return async ( ...args ) => {
		const steps = action( ...args );
		let step = steps.next();

		while ( ! step.done ) {
			try {
				step = steps.next( await step.value );
			} catch ( error ) {
				step = steps.throw( error );
			}
		}

		return step.value;
	};
}

/**
 * Wraps each generator function of a group of actions or callbacks.
 *
 * @param {Object} group The functions, by name.
 * @return {Object} The same functions, the generators made async.
 */
function wrapped( group = {} ) {
	return Object.fromEntries(
		Object.entries( group ).map( ( [ name, fn ] ) => [
			name,
			'GeneratorFunction' === fn.constructor.name ? running( fn ) : fn,
		] )
	);
}

export function store( namespace, definition ) {
	if ( definition ) {
		runtime.stores[ namespace ] = {
			state: definition.state ?? {},
			actions: wrapped( definition.actions ),
			callbacks: wrapped( definition.callbacks ),
		};
	}

	return runtime.stores[ namespace ];
}

export function getContext() {
	return runtime.context;
}

export function getConfig( namespace ) {
	return runtime.config[ namespace ] ?? {};
}

export function withSyncEvent( action ) {
	return action;
}

export default runtime;
