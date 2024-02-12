<?php
/*
 * Plugin Name: Book Reader API
 */

use Mehedi\WPQueryBuilder\DB;
use Mehedi\WPQueryBuilder\Query\Builder;
use Mehedi\WPQueryBuilder\Query\Join;
use Mehedi\WPQueryBuilder\Relations\WithOne;
use Mehedi\WPQueryBuilderExt\Relations\WithTaxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class BookReaderController extends WP_REST_Controller
{
    public $namespace = '/library/v1';

    public function register_routes()
    {
		register_rest_route($this->namespace, '/books', [
			'methods' => 'GET',
			'callback' => [$this, 'index']
		]);

	    register_rest_route($this->namespace, '/(?P<taxonomy>authors|categories)', [
		    'methods' => 'GET',
		    'callback' => [$this, 'taxonomy']
	    ]);
    }


	public function index(WP_REST_Request $request) {
		$limit = $request->get_param('limit') ?? 10;
		$lastId = $request->get_param('lastId');
		$s = $request->get_param('s');
		$termId = $request->get_param('termId');


		$query = DB::table('posts')
		          ->where('post_type', 'books')
		          ->where('post_status', 'publish')
		          ->withRelation(new WithTaxonomy('authors'), function (WithTaxonomy /** @var Builder $relation*/ $relation) {
					  $relation->taxonomy('author');
				  })
		          ->withOne('thumbnail', function ( WithOne $thumbnailQuery) {
						$thumbnailQuery->from('posts')->where('post_type', 'attachment');
				}, 'post_parent')
				->limit($limit);

		if ($lastId) {
			$query->where('ID', '<', $lastId);
		}

		if ($s) {
			$query->where('post_title', 'like', "%$s%");
		}

		if ($termId) {
			$query->join('term_relationships', 'posts.ID', '=', 'term_relationships.object_id')
			      ->join('term_taxonomy', function ( Join $join) use($termId) {
					  $join->on('term_relationships.term_taxonomy_id', '=', 'term_taxonomy.term_taxonomy_id')
					       ->where('term_taxonomy.term_id', $termId);
			      });
		}

		$data = $query->get();


		return rest_ensure_response([
			'data' => array_map(function ($post) {
				return [
					'id' => $post->ID,
					'title' => $post->post_title,
					'authors' => array_map(function ($author) {
						return [
							'name' => $author->name
						];
					}, $post->authors),
					'thumbnail' => $post->thumbnail->guid ?? null
				];
			}, $data)
		]);
	}

	public function taxonomy(WP_REST_Request $request) {
		$taxonomy_type = $request->get_param('taxonomy') === 'authors' ? 'author' : "category";
		$search = $request->get_param('s');
		$id = $request->get_param('id');

		$query = DB::table('term_taxonomy')
					->join('terms', 'term_taxonomy.term_id', '=', 'terms.term_id')
					->where('taxonomy', $taxonomy_type)
					->where('count', '>', 0)
					->orderBy('name');
		if ($search) {
			$query->where('name', 'like', "%$search%");
		}

		if ($id) {
			$query->where('terms.term_id', $id);
		}

		$data = $query->get();

		$items = array_map(function ($item) {
			return [
				'id' => $item->term_id,
				'name' => $item->name,
				'slug' => $item->slug,
				'count' => $item->count
			];
		}, $data);

		return [
			'data' => $id && !empty($items) ? $items[0] : $items
		];
	}
}

add_action( 'rest_api_init', [new BookReaderController(), 'register_routes']);
