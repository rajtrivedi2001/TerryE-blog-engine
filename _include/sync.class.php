<?php
/**
 * Article synchronisation between remote (webfusion) server and locally served copy.
 */
class Sync {
	/**
	 * Client end which runs on machine running local system. 
	 * @param $dateLastSynced DTS of the last sync operation.
	 * @param $remoteServer domain address of remote server to be synched to.
	 * @return Status report on which articles have been synched (out and in).
	 * 
	 * The client will typically be a local VM or native LAMP stack running the
	 * test/dev blog.  This will synch changes with the remote server.
	 */
	public static function client( $dateLastSynced, $remoteServer ) {

		$db = AppDB::get();
		$db->declareFunction( array(
'getChangedArticles'	=> "Set=SELECT * FROM :articles WHERE date_edited>#1 ORDER BY id",
'getArticlebyId'		=> "Row=SELECT * FROM :articles WHERE id=#1",
'createBlankSarticle'	=> "INSERT INTO :articles( id, details ) VALUES ( #1, '&nbsp;' )",
'updateLastSyncTime'	=> "UPDATE blog_config SET config_value=#1 WHERE config_name='dateLastSynced'",
		) );

		$report		= '';
		$articles	= $db->getChangedArticles( $dateLastSynced );
		if( count( $articles ) == 0 ) {
			$report .= "Nothing to do\n";
			$date_next_synced = time();
		} else { 
			// Pack the articles and use cURL to send them to the server
			foreach( $articles as $a ) { 
				$report .= "Synching article $a[id]\n"; 
			}
			$articles = gzcompress ( serialize( $articles ), 9 );
			$check = md5( AppContext::get()->salt . $articles );
			$date_next_synced = time();

			$ch = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL        => "http://$remoteServer/admin-sync",
				CURLOPT_POST       => 1,
				CURLOPT_POSTFIELDS => array( 
					'sync_content' => $articles, 
					'check'        => $check,  
					'last_synced'  => $dateLastSynced, 
					'next_synced'  => $date_next_synced,
					),
				CURLOPT_HEADER     => 0, 
				CURLOPT_RETURNTRANSFER => 1,
				) ); 

			// Get the server response and update any articles that have been tweaked on the server.
			$response = curl_exec($ch);
			curl_close($ch);

			$response = unserialize( gzuncompress( $response ) );
			$report .= "Response contains ". count( $response ) . " records\n"; 
			foreach( $response as $article ) {
				list( $id, $status ) = array( $article['id'], $article['status'] );
				if( $status == 'changed' ) {

					// The remote copy has changed so need to update the local one
					$previousArticle = $db->getArticlebyId( $id );
					if( count( $previousArticle ) == 0 ) {
						$previousArticle = NULL;
						$db->createBlankSarticle( $id );
						$status == 'local updated';
					} else {
						$status == 'local inserted';
					}
					self::updateArticle( $inputArticle, $previousArticle );				
				}
				$report .= sprintf( "%5.5u %s\n", $id, $status );
			} 
		}
		$db->updateLastSyncTime( $date_next_synced );
		return $report;
	}

	/**
	 * Server end which runs on the public web server.
	 * @param $syncContent		 The post variable containing the compressed PHP serialised articles
	 * @param $dateLastSynced    DTS of the last sync operation.
	 * @param $dateNextSynced    DTS for this sync operation.
	 * @return The compressed PHP serialised articles which have been updated on the public server
	 * 
	 * This service will be called by the client on the maintainers local system. 
	 * It synchs the changes to the public instance.
	 */

	public static function server( $syncContent, $dateLastSynced, $dateNextSynced ) {

		$db=AppDB::get();
		$db->declareFunction( array(
'getSelectedArticles'	=> "Set=SELECT * FROM :articles WHERE date_edited>#1 OR id in (#2) ORDER BY id",
'createBlankSarticle'	=> "INSERT INTO :articles( id, details ) VALUES ( #1, '&nbsp;' )",
'updateLastSyncTime'	=> "UPDATE blog_config SET config_value=#1 WHERE config_name='dateLastSynced'",
		) );

		// Unpack input articles and reindex by article ID 

		$remoteArticles = array();
		foreach (  @unserialize( gzuncompress( $syncContent ) ) as $article ) {
			$remoteArticles[(int) $article['id']] = $article;
		}
		$inputIds = implode( ',', array_keys( $remoteArticles ) );

		// Retrieve local articles including those edited past $dateLastSynced

		$outputArticles = array () ;
		foreach ( $db->getSelectedArticles( $dateLastSynced, $inputIds ) as $article ) {
			$id = $article['id'];
			if( !isset( $remoteArticles[$id] ) ) {

				// This article has been updated locally but not in the input so need to report it
				$article['status'] = 'changed';
				$outputArticles[] = $article;

			} else {

				$remoteArticle = $remoteArticles[$id];
				if( $remoteArticle['date_edited'] >= $article['date_edited'] ) {

					// The input article is more recent than article on D/B so update the D/B and report
					$outputArticles[] = array( 
						'id' => $id, 
						'status' => self::updateArticle( $remoteArticle, $article ),
						);
				} else {

					// Oops! the D/B article has a later time stamp so report out of sync. 
					// Don't return article as any merge will need to be manual  
					$outputArticles[] = array( 'id' => $article['id'], 'status' => 'sync error' );
				}
				unset( $remoteArticles[$id] );
			}
		}

		// Any articles left in $remoteArticles are new inserts
		foreach( $remoteArticles as $article ) {
			$id = $article['id'];
			$db->createBlankSarticle( $id );
			self::updateArticle( $inputArticle );
			$outputArticles[] = array( 'id' => $article['id'], 'status' => 'created' );
		}

		$db->updateLastSyncTime( $dateNextSynced );
		AuthorArticle::get()->regenKeywords();

		return $outputArticles;
	}

	/**
	 * Update an article in the database
	 */
	private static function updateArticle( 
			$newArticle, 
			$previousArticle = array( 'flag' 	=> 0,  'author'   => '', 'date_edited' => 0,   'date' => 0, 
				                      'comments'=> '', 'keywords' => '', 'comment_count' => 0, 'encoding' => 'HTML', 
			                          'title'	=> '', 'details'  => '', 'trim_length' => 0)
			) {
		$db = AppDB::get();
		$id = $newArticle['id'];
		unset( $newArticle['id'] );		// we don't need to reset the primary key

		$setClause = '';
		reset( $newArticle );
		while ( list( $field, $value ) = each( $newArticle ) ) {
			// Create ",field=<escaped contents>" for each changed field
			if( $previousArticle[$field] != $value ) {
				$setClause .= ", $field='{$db->escape_string( $value )}'";
			}
		}
		
		if( $setClause == '' ) {
			return 'same';
		} else {
			// Update the article if any fields are modified
			$db = AppDB::get();
			$db->query( "UPDATE {$db->tablePrefix}articles SET ". substr( $setClause, 1) . " WHERE id='$id'" );
			return "updated";
		}
	}
}
