<?php
/*
 * Plugin Name: Book Reader API
 */

use Mehedi\WPQueryBuilder\DB;
use Mehedi\WPQueryBuilder\Query\Builder;
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
    }


	public function index(WP_REST_Request $request) {
		$limit = $request->get_param('limit') ?? 10;
		$lastId = $request->get_param('lastId');
		$s = $request->get_param('s');

		$query = DB::table('posts')
		          ->where('post_type', 'books')
		          ->where('post_status', 'publish')
		          ->withRelation(new WithTaxonomy('authors'), function (WithTaxonomy /** @var Builder $relation*/ $relation) {
					  $relation->taxonomy('author');
				  })
		          ->withOne('thumbnail', function ( WithOne $thumbnailQuery) {
						$thumbnailQuery->from('posts')->where('post_type', 'attachment');
				}, 'post_parent')
				->orderBy('ID', 'desc')
				->limit($limit);

		if ($lastId) {
			$query->where('ID', '<', $lastId);
		}

		if ($s) {
			$query->where('post_title', 'like', "%$s%");
		}


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
					'thumbnail' => $post->thumbnail?->guid
				];
			}, $query->get())
		]);
	}
}

add_action( 'rest_api_init', [new BookReaderController(), 'register_routes']);