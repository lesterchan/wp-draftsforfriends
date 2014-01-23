(function ($) {
	// Form
	var shareFormAdd =  $( '#draftsforfriends-add' ),
		shareTableCurrent = $( '#draftsforfriends-current' );

	// Add shared draft event
	$( 'input[name="draftsforfriends_submit"]', shareFormAdd).on( 'click', function () {
		var sharedDraft = {
			'id': 1, // Adding this to pass empty validation check
			'post_id': parseInt ( $( 'select[name="post_id"]', shareFormAdd ).val(), 10),
			'expires': parseInt( $( 'input[name="expires"]', shareFormAdd ).val(), 10),
			'measure': $( 'select[name="measure"]', shareFormAdd ).val(),
			'nonce': $( '#draftsforfriends-add-nonce' ).val()
		};

		// Basic validation before submitting
		if ( draftsForFriends.validate( sharedDraft ) )
			draftsForFriends.addSharedDraft( sharedDraft );
	});

	// Extend shared draft event - Submit
	shareTableCurrent.on( 'click', 'input[name="draftsforfriends_extend_submit"]', function ( evt ) {
		var shareFormExtend = $( this).parent( 'form' ),
			id = parseInt ( $( 'input[name="id"]', shareFormExtend ).val(), 10),
			sharedDraft = {
				'id': id,
				'post_id': parseInt( $( 'input[name="post_id"]', shareFormExtend ).val(), 10 ),
				'expires': parseInt( $( 'input[name="expires"]', shareFormExtend ).val(), 10 ),
				'measure': $( 'select[name="measure"]', shareFormExtend ).val(),
				'nonce': $( '#draftsforfriends-extend-' + id + '-nonce' ).val()
			};

		// Basic validation before submitting
		if ( draftsForFriends.validate( sharedDraft ) )
			draftsForFriends.extendSharedDraft( sharedDraft );
	});

	// Extend shared draft event - Showing the extend form
	shareTableCurrent.on( 'click', 'a.expand', function ( evt ) {
		evt.preventDefault();
		var id = $( this ).data( 'id' );
		$( '#draftsforfriends-current-' + id )
			.find( '.expanded' )
			.css( 'display', 'inline' )
			.end()
			.find( '.collapsed' )
			.css( 'display', 'none' )
			.end()
			.find( '.row-actions' )
			.css( 'visibility', 'visible' );
	});

	// Extend shared draft event - Hiding the extend form
	shareTableCurrent.on( 'click', 'a.collapse', function ( evt ) {
		evt.preventDefault();
		var id = $( this ).data( 'id' );
		$( '#draftsforfriends-current-' + id )
			.find( '.collapsed' )
			.css( 'display', 'inline' )
			.end()
			.find( '.expanded' )
			.css( 'display', 'none' )
			.end()
			.find( '.row-actions' )
			.removeAttr( 'style' );
	});

	// Delete shared draft event
	shareTableCurrent.on( 'click', 'a.delete', function ( evt ) {
		evt.preventDefault();

		var sharedDraft = {
			'id': parseInt( $( this ).data( 'id' ), 10 ),
			'post_id': parseInt( $( this ).data( 'post_id' ), 10 ),
			'post_title': $( this ).data( 'post_title' ),
			'expires': 1, // Adding this to pass empty validation check
			'measure': 's', // Adding this to pass empty validation check
			'nonce': $( this ).data( 'nonce' )
		};

		if ( confirm ( draftsForFriendsAdminL10n.confirm_delete.replace( '{{post_title}}', sharedDraft.post_title ) ) ) {
			if ( draftsForFriends.validate( sharedDraft ) ) {
				draftsForFriends.deleteSharedDraft( sharedDraft );
			}
		}
	});

	// Base
	var draftsForFriends = {
		// Show success or error message in UI
		_showMessage: function( type, message ) {
			$( '#draftsforfriends-message')
				.text( message )
				.removeClass()
				.addClass( 'updated fade ' + type  )
				.show();
		},

		_toggleEmptyMessage: function ( showHide ) {
			if ( 'show' == showHide ) {
				if ( $( '.empty-row', shareTableCurrent).length ) {
					$( '.empty-row', shareTableCurrent).show();
				} else {
					$( '<tr class="empty-row"><td colspan="6" style="text-align: center;">' + draftsForFriendsAdminL10n.no_shared_drafts + '</td></tr>').appendTo( $( 'tbody', shareTableCurrent) );
				}
			// Hide empty message
			} else {
				if ( $( '.empty-row', shareTableCurrent).length ) {
					$( '.empty-row', shareTableCurrent).hide();
				}
			}
		},

		// Validate shared draft before submitting
		validate: function( sharedDraft ) {
			if ( ( isNaN( sharedDraft.id ) || 0 >= sharedDraft.id ) ) {
				alert( draftsForFriendsAdminL10n.error_id );
				return false;
			} else if ( ( isNaN( sharedDraft.post_id ) || 0 >= sharedDraft.post_id ) ) {
				alert( draftsForFriendsAdminL10n.error_post_id );
				return false;
			} else if ( ( isNaN( sharedDraft.expires ) || 0 >= sharedDraft.expires ) ) {
				alert( draftsForFriendsAdminL10n.error_expires );
				return false;
			} else if ( '' === $.trim( sharedDraft.nonce ) ) {
				alert( draftsForFriendsAdminL10n.empty_nonce );
				return false;
			} else if ( '' === $.trim( sharedDraft.measure ) ) {
				alert( draftsForFriendsAdminL10n.empty_measure );
				return false;
			}
			return true;
		},

		// Add shared draft
		addSharedDraft: function( sharedDraft ) {
			var self = this;

			var add_ajax = $.ajax({
				type: 'post',
				dataType : 'json',
				url: draftsForFriendsAdminL10n.admin_ajax_url,
				data: 'action=draftsforfriends_admin&do=add' +
					'&post_id=' + sharedDraft.post_id +
					'&expires=' + sharedDraft.expires +
					'&measure=' + sharedDraft.measure +
					'&_ajax_nonce=' + sharedDraft.nonce,
				cache: false
			}).done( function( data ) {
				if ( data.success ) {
					// Show success message
					self._showMessage( 'success', data.success );

					// Append newly addded shared draft to table
					$( data.html ).hide().prependTo( $( 'tbody', shareTableCurrent) ).fadeIn();

					// Increment shared draft count
					$( '#draftsforfriends-current-count').text( data.count );

					// If currently is showing empty message, hide it
					self._toggleEmptyMessage( 'hide' );
				} else {
					self._showMessage( 'error', data.error );
				}
			}).fail( function( jqXHR, textStatus, errorThrown ) {
				self._showMessage( 'error', errorThrown );
			});

			// Return promise
			return add_ajax;
		},

		// Extend shared draft
		extendSharedDraft: function( sharedDraft ) {
			var self = this;

			var extend_ajax = $.ajax({
				type: 'post',
				dataType : 'json',
				url: draftsForFriendsAdminL10n.admin_ajax_url,
				data: 'action=draftsforfriends_admin&do=extend' +
					'&id=' + sharedDraft.id +
					'&post_id=' + sharedDraft.post_id +
					'&expires=' + sharedDraft.expires +
					'&measure=' + sharedDraft.measure +
					'&_ajax_nonce=' + sharedDraft.nonce,
				cache: false
			}).done( function( data ) {
				if ( data.success ) {
					// Show success message
					self._showMessage( 'success', data.success );

					// Update DOM with new data
					var dom = '#draftsforfriends-current-' + data.shared.id;
					$( dom ).replaceWith( data.html );
					// We need to query the DOM again because it is being replaced
					$( dom ).css( 'background-color', '#dff0d8' ).animate( { backgroundColor: '#fcfcfc' }, 1000 );
				} else {
					self._showMessage( 'error', data.error );
				}
			}).fail( function( jqXHR, textStatus, errorThrown ) {
				self._showMessage( 'error', errorThrown );
			});

			// Return promise
			return extend_ajax;
		},

		// Delete shared draft
		deleteSharedDraft: function( sharedDraft ) {
			var self = this;

			var delete_ajax = $.ajax({
				type: 'post',
				dataType : 'json',
				url: draftsForFriendsAdminL10n.admin_ajax_url,
				data: 'action=draftsforfriends_admin&do=delete' +
					'&id=' + sharedDraft.id +
					'&post_id=' + sharedDraft.post_id +
					'&_ajax_nonce=' + sharedDraft.nonce,
				cache: false
			}).done( function( data ) {
				if ( data.success ) {
					// Show success message
					self._showMessage( 'success', data.success );

					// Fade out the deleted shared draft
					var deleteDom = $( '#draftsforfriends-current-' + data.shared.id );
					if ( deleteDom.length ) {
						deleteDom.fadeOut( 'normal', function() {
							this.remove();

							// If no more shared draft left, show empty messsage
							if( 0 == $( '> tbody > tr:not(.empty-row)', shareTableCurrent ).length ) {
								self._toggleEmptyMessage( 'show' );
							}
						});
					}

					// Decrement shared draft count
					$( '#draftsforfriends-current-count').text( data.count );
				} else {
					self._showMessage( 'error', data.error );
				}
			}).fail( function( jqXHR, textStatus, errorThrown ) {
				self._showMessage( 'error', errorThrown );
			});

			// Return promise
			return delete_ajax;
		}
	}
}(jQuery));