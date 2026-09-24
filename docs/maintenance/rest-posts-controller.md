# The product's REST controller against WordPress core

`SEOCart\Catalog\Interfaces\Rest\ProductPostsController` serves `wp/v2/seocart-products`. It
extends core's `WP_REST_Posts_Controller` and replaces the body of `create_item()` and
`update_item()`, so that the post and the product's commerce data are saved in one call to
`SaveProduct`. Everything else is core's. Because two of core's methods are reproduced rather
than called, each WordPress major must be diffed against this list.

**Checked against WordPress 7.1.1**, `wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php`.

## How to re-check on a new WordPress major

1. Diff `create_item()` and `update_item()` of `WP_REST_Posts_Controller` between the version
   below and the new one. Any change to the steps listed under "Reproduced" must be carried into
   `ProductPostsController::create_item()`, `update_item()`, `write()`, `applyCreateFields()`,
   `applyUpdateFields()` or `applyTermsAndMeta()`, in the same order.
2. Diff the methods listed under "Depended on". A change of signature or of meaning there can
   break the controller without a change to its own code.
3. Run `composer test:integration -- --filter "ProductPostsController|AutosaveAndRevision|RoutePermissionWalk"`
   and the `product-editor` end-to-end spec.
4. Update the version in bold above.

## Overridden

| Method                                  | What the override does                                                                                                                                                                                          |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `__construct( $post_type )`             | Calls the parent, then keeps the services the kernel gives. WordPress itself passes the post type only; an instance it builds that way refuses to save.                                                         |
| `get_item_schema()`                     | Calls the parent, then adds the `seocart` property compiled from the commerce field declarations. The parent's `get_endpoint_args_for_item_schema()` derives the create and update arguments from it unchanged. |
| `create_item( $request )`               | Reproduces core's create sequence around one product save; see below. A request with an `id` is handed to the parent, whose first step refuses it.                                                              |
| `update_item( $request )`               | Reproduces core's update sequence around one product save; see below.                                                                                                                                           |
| `prepare_item_for_response( $item, … )` | Calls the parent, remembering the post it prepares for the override below.                                                                                                                                      |
| `add_additional_fields_to_object()`     | Calls the parent, then adds the `seocart` object, unless `_fields` leaves it out or the request is `HEAD`. Core calls it before its context filter and `rest_prepare_{$post_type}`, which both see the object.  |

No permission method is overridden: reading and writing a product are decided by core's
methods, through the post type's mapped capabilities.

## Reproduced

`create_item()` in 7.1.1 (lines 741–883), in order. The controller keeps every step but the
insert itself, which `SaveProduct` makes:

1. `rest_post_exists` (400) when the request has an `id` — the parent's own answer.
2. `prepare_item_for_database( $request )`, which ends in the `rest_pre_insert_{$post_type}` filter; a `WP_Error` is returned as is.
3. `$prepared_post->post_type = $this->post_type`.
4. For a `draft` or `pending` post with a `post_name`: `wp_unique_post_slug( name, id, 'publish', type, parent )`. Core passes `$prepared_post->id`, which is never set on a create; the controller passes `0`, which is what that amounts to.
5. `wp_insert_post( wp_slash( (array) $prepared_post ), true, false )` — **replaced** by `SaveProduct::save()`, which calls the same function inside its transaction.
6. `get_post( $post_id )`, then `do_action( "rest_insert_{$post_type}", $post, $request, true )`.
7. Sticky, when the schema has `sticky`: `stick_post()` or `unstick_post()`.
8. `handle_featured_media()`, when the schema has `featured_media` and the request sets it.
9. `set_post_format()`, when the schema has `format` and the request sets it.
10. `handle_template( $template, $post_id, true )`, when the schema has `template` and the request sets it.
11. `handle_terms()`; a `WP_Error` is returned.
12. `$this->meta->update_value()`, when the schema has `meta` and the request sets it; a `WP_Error` is returned.
13. `get_post( $post_id )` again, then `update_additional_fields_for_object()`; a `WP_Error` is returned.
14. `$request->set_param( 'context', 'edit' )`.
15. `do_action( "rest_after_insert_{$post_type}", $post, $request, true )`.
16. `wp_after_insert_post( $post, false, null )` — through `PostGateway::fireAfterInsert()`.
17. The response of `prepare_item_for_response()`, status 201, with a `Location` header from `rest_get_route_for_post()`.

`update_item()` in 7.1.1 (lines 944–1055), in order:

1. `get_post( $request['id'] )` of the controller (404 `rest_post_invalid_id`), then `$post_before = get_post( … )`.
2. `prepare_item_for_database( $request )`.
3. The status: the prepared one, or the post's.
4. For a `draft` or `pending` post with a `post_name`: `wp_unique_post_slug( name, ID, 'publish', type, parent )`.
5. `wp_update_post( wp_slash( (array) $post ), true, false )` — **replaced** by `SaveProduct::save()`.
6. `get_post()`, then `do_action( "rest_insert_{$post_type}", $post, $request, false )`.
7. `set_post_format()`, then `handle_featured_media()`, then sticky, then `handle_template( $template, $post->ID )`, each under the same condition as on a create — the order differs from the create's, and the controller keeps each.
8. `handle_terms()`, then `$this->meta->update_value()`.
9. `get_post()` again, then `update_additional_fields_for_object()`.
10. `$request->set_param( 'context', 'edit' )`.
11. The attachment branch is not reproduced: the product is never an attachment.
12. `do_action( "rest_after_insert_{$post_type}", $post, $request, false )`.
13. `wp_after_insert_post( $post, true, $post_before )` — through `PostGateway::fireAfterInsert()`.
14. The response of `prepare_item_for_response()`.

Steps 6 onwards of either sequence run after the product's transaction has committed, as core
runs them after its insert: another plugin's `rest_insert_`, `rest_after_insert_`, REST field,
meta and `wp_after_insert_post` callbacks never run inside it, and a failure among them is
returned as core returns it, with the product saved. What core runs inside the post write
itself, `save_post` and the status transitions among it, runs inside the save's window, as the
product write's recovery allows for.

### Where the controller differs from core

- A save WordPress or the product refuses is answered with the plugin's error: a refused post
  write is `catalog.post_rejected` (400), WordPress's own code in `details.wordpress_code`, where
  core returns WordPress's error; a SKU another product holds is `catalog.sku_taken` (409).
- None other in the write: core's `prepare_item_for_database()` always sets the post's type, so
  even an update that sends only the `seocart` object writes the post, and `save_post` fires as
  it does for core.

## Depended on

Inherited unchanged, and relied on by the overrides:

| Core member                                                                               | What the controller relies on                                                                              |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `prepare_item_for_database()`                                                             | Maps the request to the post's fields and applies `rest_pre_insert_{$post_type}`; knows no commerce field. |
| `get_post()`                                                                              | Refuses an id that is not a post of this type with 404.                                                    |
| `handle_featured_media()`, `handle_template()`, `handle_terms()`, `$meta`                 | The steps after the insert, called exactly as core calls them.                                             |
| `update_additional_fields_for_object()`                                                   | Runs other plugins' `register_rest_field()` update callbacks.                                              |
| `get_fields_for_response()`, `filter_response_by_context()`                               | `_fields`, and the `context` filtering core applies to the `seocart` object, nested properties included.   |
| `add_additional_fields_schema()`                                                          | Other plugins' fields in the schema the override returns.                                                  |
| `register_routes()`, the permission methods, `get_item()`, `get_items()`, `delete_item()` | Unchanged.                                                                                                 |

And outside the controller:

- `WP_Post_Type::get_rest_controller()` builds the controller from `rest_controller_class` with the
  post type's name only, keeps it in the public `rest_controller` property, and returns it only
  when it is an instance of that class. The kernel puts its own instance there on `rest_api_init`,
  before `create_initial_rest_routes()` runs at priority 99. `WP_REST_Posts_Controller::__construct()`
  reads the registered post type, so `ProductPostsController::install()` builds nothing when the
  type is not registered or names another class.
- `create_initial_rest_routes()` registers the controller's routes, then builds the revision and
  autosave controllers with `get_revisions_rest_controller()` and `get_autosave_rest_controller()`,
  which take `get_rest_controller()` as their parent. Both keep what they built in the public
  `revisions_rest_controller` and `autosave_rest_controller` properties, so a pair built earlier
  would keep an earlier parent; `ProductPostsController::install()` sets both back to `null`,
  their default, and core builds them again around the installed instance.
- `WP_REST_Autosaves_Controller::create_item()` prepares the post with the parent's
  `prepare_item_for_database()`; for a draft by its author it calls `wp_update_post()` on the post
  itself, otherwise it writes an autosave revision. It never calls the controller's
  `update_item()`, so an autosave never saves the product's commerce data. It defines
  `DOING_AUTOSAVE` for the rest of the request.
- `wp_restore_post_revision()` is `wp_update_post()` of the revisioned fields; it does not reach
  the controller.
