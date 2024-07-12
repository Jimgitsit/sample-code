package com.badbob.app.getaclue;

import java.io.IOException;
import java.net.UnknownHostException;
import java.util.ArrayList;

import org.apache.http.conn.HttpHostConnectException;

import com.facebook.android.DialogError;
import com.facebook.android.Facebook;
import com.facebook.android.FacebookError;
import com.facebook.android.Facebook.DialogListener;
import com.facebook.android.Facebook.ServiceListener;

import com.badbob.app.getaclue.Match.PlayerAction;
import com.badbob.app.getaclue.SessionEvents.AuthListener;
import com.badbob.app.getaclue.SessionEvents.LogoutListener;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.NotificationManager;
import android.app.ProgressDialog;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.DialogInterface;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.content.SharedPreferences.Editor;
import android.graphics.Typeface;
import android.graphics.drawable.AnimationDrawable;
import android.net.Uri;
import android.os.AsyncTask;
import android.os.Build;
import android.os.Bundle;
import android.util.Log;
import android.view.View;
import android.view.View.OnClickListener;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import com.google.ads.*;

public class MainActivity extends Activity {
	
	//private boolean loginComplete = false;
	private boolean matchListInitialized = false;
	
	public ProgressDialog progress;
	
	// This is to prevent double clicks
	private boolean haltClicks = false;
	
	//private boolean fbAuthFailed = false;
	//private String fbAuthError = "";
	
	private boolean showingNetworkError = false;
	
	private MatchListTask matchListTask;
	
	private int activeMatchCount = 0;
	private int finishedMatchCount = 0;
	private View matchListView = null;
	
	private BroadcastReceiver refreshMatchesBroadcastReceiver = null;
	
	private static final String LOG_TAG = "GetAClue::MainActivity";
	
	public static final int DELETE_MATCH_RESULT_CODE = 10;
	public static final int REINIT_RESULT_CODE = 20;
	public static final int REFRESH_TOKENS_WITH_ANIM_RESULT_CODE = 30;
	public static final int AD_FREE_UPGRADE_PURCHASED_RESULT_CODE = 40;
	
	public static final int MATCH_ACTIVITY_REQUEST = 1;
	public static final int LOGIN_ACTIVITY_REQUEST = 2;
	public static final int NEW_MATCH_ACTIVITY_REQUEST = 3;
	public static final int MATCH_SUMMARY_ACTIVITY_REQUEST = 4;
	public static final int FB_ACTIVITY_REQUEST = 32665;
	public static final int SETTINGS_ACTIVITY_REQUEST = 5;
	public static final int GET_TOKENS_ACTIVITY_REQUEST = 6;
	public static final int ADD_FREE_UPGRADE_ACTIVITY_REQUEST = 7;
	
	public static final int LOGIN_MODE_NOT_SET = 0;
	public static final int LOGIN_MODE_BASIC = 1;
	public static final int LOGIN_MODE_FACEBOOK = 2;
	
	public static final int MAX_ACTIVE_MATCHES = 10;
	public static final int MAX_FINISHED_MATCHES = 10;
	
	private MatchListItemBase lastMatchClicked = null;
	
	Button startNewBtn = null;
	Button settingsBtn = null;
	Button tokensBtn = null;
	Button removeAdsBtn = null;
	ImageView loadingImg = null;
	LinearLayout highScoreLayout = null;
	
	//private int soundIndex = 0;
	
	private Facebook facebook;
	
	private AdView adView = null;
	
	@Override
	public void onCreate( Bundle savedInstanceState ) {
		super.onCreate( savedInstanceState );
		
		Log.i( LOG_TAG, "onCreate" );
		
		setContentView( R.layout.main );
		setVolumeControlStream( GetAClueApp.getAppVolumeControlStream() );
		
		GetAClueApp.fixBackgroundRepeat( ( (ViewGroup)findViewById( android.R.id.content ) ).getChildAt( 0 ) );
		
		startNewBtn = (Button)findViewById( R.id.startNewMatchButton );
		settingsBtn = (Button)findViewById( R.id.settingsButton );
		tokensBtn = (Button)findViewById( R.id.getTokensButton );
		removeAdsBtn = (Button)findViewById( R.id.removeAdsButton );
		highScoreLayout = (LinearLayout)findViewById( R.id.highScoreLayout );
		loadingImg = (ImageView)findViewById( R.id.loadingImg );
		
		facebook = GetAClueApp.getFacebook();
		SessionStore.restore( facebook, getApplicationContext() );
		SessionEvents.addAuthListener( new FacebookAuthListener() );
		SessionEvents.addLogoutListener( new FacebookLogoutListener() );
		
		setFonts();
		
		// For debugging so that clicking on the title bar will refresh the match list
		ImageView view = (ImageView)findViewById( R.id.imageView1 );
		view.setOnClickListener( new OnClickListener() {
			@Override
			public void onClick( View arg0 ) {
				refreshMatchList();
			}
		} );
		
		setTokenText( false );
		
		setVersionText();
		setServerHostText();
		
		init();
	}
	
	private void init() {
		new AsyncTask<Void, Void, Void>() {
			
			boolean networkError = false;
			boolean facebookError = false;
			boolean noLogin = false;
			boolean badLogin = false;
			Exception e = null;
			boolean doingFacebookLogin = false;
			boolean updateRequired = false;
			int loginMode = -1;
			
			@Override
			protected void onPreExecute() {
				LinearLayout matchListLayout = (LinearLayout)findViewById( R.id.matchListLayout );
				matchListLayout.removeAllViews();
				
				//progress = ProgressDialog.show( MainActivity.this, "", "Loading...", true );
				startNewBtn.setVisibility( View.GONE );
				settingsBtn.setVisibility( View.GONE );
				tokensBtn.setVisibility( View.GONE );
				removeAdsBtn.setVisibility( View.GONE );
				highScoreLayout.setVisibility( View.GONE );
				
				loadingImg.setVisibility( View.VISIBLE );
				loadingImg.setBackgroundResource( R.drawable.loading_animation );
				AnimationDrawable frameAnim = (AnimationDrawable)loadingImg.getBackground();
				frameAnim.start();
				
				SharedPreferences prefs = GetAClueApp.getPrefs();
				loginMode = prefs.getInt( "LoginMode", LOGIN_MODE_NOT_SET );
			}

			@Override
			protected Void doInBackground( Void... params ) {
				// TODO: Does this keep running if the activity finishes???
				try {
					// *************************
					// *** Check app version ***
					// *************************
					WebServiceAdapter wsa = GetAClueApp.getWSAdapter();
					String serverVersion = wsa.getAppVersion();
					String appVersion = getResources().getString( R.string.version_release );
					
					if( GetAClueApp.requiresUpdate( serverVersion, appVersion ) ) {
						updateRequired = true;
						return null;
					}
					
					// ****************
					// *** Do login ***
					// ****************
					if( loginMode == LOGIN_MODE_NOT_SET ) {
						noLogin = true;
						
						if( progress != null && progress.isShowing() ) {
							progress.dismiss();
						}
						
						// Show the login activity to get the login mode
						Intent myIntent = new Intent( MainActivity.this, LoginActivity.class );
						startActivityForResult( myIntent, LOGIN_ACTIVITY_REQUEST );
					}
					else if( loginMode == LOGIN_MODE_BASIC ) {
						// Create the thisPlayer
						SharedPreferences prefs = GetAClueApp.getPrefs();
						int id = prefs.getInt( "UserID", -1 );
						if( id != -1 ) {
							Player thisPlayer = Player.fromBasicId( id );
							if( thisPlayer == null ) {
								// Player was not found on server or is otherwise corrupted so reset everything
								badLogin = true;
							}
							else {
								GetAClueApp.setThisPlayer( thisPlayer );
							}
						}
						else {
							// No id in prefs
							badLogin = true;
						}
					}
					else if( loginMode == LOGIN_MODE_FACEBOOK ) {
						if( !facebook.isSessionValid() ) {
							doingFacebookLogin = true;
							//facebook.authorize( MainActivity.this, new String[] { "publish_stream", "offline_access" }, new LoginDialogListener() );
							facebook.authorize( MainActivity.this, new String[] { "offline_access" }, new LoginDialogListener() );
							// FacebookAuthListener gets called when authorize completes
							// which then calls postLogin
						}
						else {
							facebook.extendAccessTokenIfNeeded( MainActivity.this, new ServiceListener() {
								@Override
								public void onComplete( Bundle values ) {
									// Save the new token
									SessionStore.save( facebook, getApplicationContext() );
									Log.i( LOG_TAG, "Facebook access token extended." );
								}
								
								@Override
								public void onFacebookError( FacebookError error ) {
									// TODO: What to do in this case?
									Log.e( LOG_TAG, error.getMessage() );
								}
								
								@Override
								public void onError( Error e ) {
									// TODO: What to do in this case?
									Log.e( LOG_TAG, e.getMessage() );
								}
							} );
							
							// Get from the server and set thisPlayer
							SessionStore.save( facebook, getApplicationContext() );
							Player thisPlayer = Player.fromFacebookId( "me", true );
							if( thisPlayer == null ) {
								// Player could not be found. Reqeust login again
								badLogin = true;
							}
							else {
								GetAClueApp.setThisPlayer( thisPlayer );
							}
						}
					}
					
					return null;
				}
				catch( FacebookError e ) {
					facebookError = true;
					this.e = e;
				}
				catch( Exception e ) {
					networkError = true;
					this.e = e;
				}
				
				return null;
			}
			
			@Override
			protected void onPostExecute( Void voids ) {
				if( networkError ) {
					if( progress != null && progress.isShowing() ) {
						progress.dismiss();
					}
					onNetworkError( e );
					return;
				}
				
				if( facebookError ) {
					if( progress != null && progress.isShowing() ) {
						progress.dismiss();
					}
					// Trouble with facebook, just logout
					Log.e( LOG_TAG, e.getMessage() );
					new LogoutTask().execute();
					return;
				}
				
				if( updateRequired ) {
					if( progress != null && progress.isShowing() ) {
						progress.dismiss();
					}
					
					// Show dialog for update
					DialogInterface.OnClickListener dialogClickListener = new DialogInterface.OnClickListener() {
						@Override
						public void onClick( DialogInterface dialog, int which ) {
							switch( which ) {
								case DialogInterface.BUTTON_POSITIVE: {
									// Yes button clicked
									if( GetAClueApp.REDIRECT_TO_MARKET_ON_UPDATE ) {
										// Redirect to market
										Uri marketUri = Uri.parse( "market://details?id=" + getResources().getString( R.string.package_name ) );
										Intent marketIntent = new Intent( Intent.ACTION_VIEW, marketUri );
										startActivity( marketIntent );
									}
									
									finish();
									break;
								}
								case DialogInterface.BUTTON_NEGATIVE:
								case DialogInterface.BUTTON_NEUTRAL: {
									// No or OK button clicked
									finish();
									break;
								}
							}
						}
					};
					
					AlertDialog.Builder builder = new AlertDialog.Builder( MainActivity.this );
					builder.setCancelable( false );
					//builder.setTitle( "Update available" );
					if( GetAClueApp.REDIRECT_TO_MARKET_ON_UPDATE ) {
						builder.setMessage( "An update for Get a Clue is available.\n\nWould you like to download it now?" );
						builder.setPositiveButton( "Yes", dialogClickListener );
						builder.setNegativeButton( "No", dialogClickListener );
					}
					else {
						builder.setMessage( "An update for Get a Clue is needed before you can play. Please install the latest apk from dropbox.\n\nIf it doesn't work try uninstalling first." );
						builder.setNeutralButton( "Ok", dialogClickListener );
					}
					
					builder.show();
					return;
				}
				
				if( badLogin ) {
					SharedPreferences prefs = GetAClueApp.getPrefs();
					int id = prefs.getInt( "UserID", -1 );
					Log.i( LOG_TAG, "Bad login id from prefs. id = " + id + " - Resetting prefs." );
					
					// Clear the LoginMode preference and re-initialize
					Editor editor = prefs.edit();
					editor.remove( "LoginMode" );
					editor.commit();
					
					// init();
					new LogoutTask().execute();
					return;
				}
				
				// If we are doing a facebook login then postLogin gets 
				// called from the FacebookAuthListener
				if( loginMode != -1 && !doingFacebookLogin && !noLogin ) {
					postLogin();
				}
			}
			
		}.execute();
	}
	
	private void initAds() {
		Player thisPlayer = GetAClueApp.getThisPlayer();
		if( thisPlayer != null && thisPlayer.showAds() && adView == null ) {
			// Create the adView
			adView = new AdView( this, AdSize.SMART_BANNER, GetAClueApp.ADMOB_PUBLISHER_ID );
			
			// Lookup your LinearLayout assuming it's been given
			// the attribute android:id="@+id/mainLayout"
			LinearLayout layout = (LinearLayout)findViewById( R.id.mainLayout );
			
			// Add the adView to it
			layout.addView( adView );
			
			final AdRequest adReq = new AdRequest();
			if( GetAClueApp.ADMOB_USE_TEST_ADS ) {
				ArrayList<String> testDevices = GetAClueApp.getAdMobTestDevices();
				for( String testDevice : testDevices ) {
					adReq.addTestDevice( testDevice );
				}
			}
			
			// Initiate a generic request to load it with an ad
			adView.loadAd( adReq );
		}
	}
	
	public class MatchListBroadcastReceiver extends BroadcastReceiver {
		@Override
		public void onReceive( Context context, Intent intent ) {
			if( intent.getAction().compareTo( GetAClueApp.REFRESH_MATCH_LIST_ACTION ) == 0 ) {
				Log.i( LOG_TAG, "Received refresh match list broadcast" );
				refreshMatchList();
			}
		}
	};
	
	/*
	// This gets called when a notification is tapped and the app is running
	@Override
	public void onNewIntent( Intent intent ) {
		Log.i( LOG_TAG, "onNewIntent" );
		if( intent.getAction() == GetAClueApp.NOTIFICATION_ACTION ) {
			int matchId = intent.getIntExtra( "match_id", -1 );
			if( matchId != -1 && matchListView != null ) {
				// Find the match in question
				int childCount = ((ViewGroup)matchListView).getChildCount();
				for( int i = 0; i < childCount; i++ ) {
					View view = (View)((ViewGroup)matchListView).getChildAt( i );
					if( view.getId() == R.id.matchListItemLayout && ((MatchListItemActive)view).getMatchId() == matchId ) {
						// Open this match
						onMatchClick( view );
					}
				}
			}
		}
	}
	*/
	
	// This gets called when a notification is tapped and the app is running
	@Override
	public void onNewIntent( Intent intent ) {
		Log.i( LOG_TAG, "onNewIntent" );
		refreshMatchList();
	}
	
	@Override
	public void onResume() {
		Log.i( LOG_TAG, "onResume" );
		super.onResume();
		
		//setTokenText( false );
		
		// Register to receive messages from the GCMIntentService for refreshing the match list
		if( refreshMatchesBroadcastReceiver == null ) {
			refreshMatchesBroadcastReceiver = new MatchListBroadcastReceiver();
			registerReceiver( refreshMatchesBroadcastReceiver, new IntentFilter( GetAClueApp.REFRESH_MATCH_LIST_ACTION ) );
		}
		
		if( lastMatchClicked != null ) {
			// Hide the animation
			lastMatchClicked.hideBusyIndicator();
		}
		
		/*
		// Clear any notifications
		Handler handler = new Handler();
		Runnable clearNotifications = new Runnable() {
			@Override
			public void run() {
				NotificationManager notificationManager = (NotificationManager)getSystemService( Context.NOTIFICATION_SERVICE );
				notificationManager.cancel( GetAClueApp.APP_NOTIFICATION_ID );
			}
		};
		handler.postDelayed( clearNotifications, 5000 );
		*/
	}
	
	@Override
	public void onRestart() {
		Log.i( LOG_TAG, "onRestart" );
		super.onRestart();
	}
	
	@Override
	public void onPause() {
		Log.i( LOG_TAG, "onPause" );
		super.onPause();
	}
	
	@Override
	public void onStop() {
		Log.i( LOG_TAG, "onStop" );
		super.onStop();
	}
	
	@Override
	public void onDestroy() {
		Log.i( LOG_TAG, "onDestroy" );
		
		if( GetAClueApp.getFriendsTask != null ) {
			GetAClueApp.getFriendsTask.cancel( true );
		}
		
		// Unregister the broadcast receiver for matchlist updates
		if( refreshMatchesBroadcastReceiver != null ) {
			unregisterReceiver( refreshMatchesBroadcastReceiver );
			refreshMatchesBroadcastReceiver = null;
		}
		
		// Notify all the match list items that we are being destroyed
		if( matchListView != null ) {
			for( int i = 0; i < ( (ViewGroup)matchListView ).getChildCount(); ++i ) {
				try {
					MatchListItemBase item = (MatchListItemBase)( (ViewGroup)matchListView ).getChildAt( i );
					item.onDestroy();
				}
				catch( ClassCastException e ) {
					// This happens when it runs across a header and can safely be ignored
					continue;
				}
			}
		}
		
		super.onDestroy();
	}
	
	private void refreshMatchList() {
		Log.i( LOG_TAG, "Refreshing match list" );
		
		// Show the refreshing animation
		ImageView anim = (ImageView)findViewById( R.id.refreshingAnim );
		if( anim != null ) {
			anim.setVisibility( View.VISIBLE );
		}
		
		// onMatchListTaskComplete will be called when this task finishes
		matchListTask = new MatchListTask( this );
		matchListTask.execute( MatchListTask.COMBINED_TURN );
	}
	
	// This is called when MatchListTask is completed
	public void onMatchListTaskComplete( LinearLayout view ) {
		if( view != null ) {
			matchListView = view;
			
			LinearLayout matchListLayout = (LinearLayout)findViewById( R.id.matchListLayout );
			matchListLayout.removeAllViews();
			//if( matchListInitialized ) {
			//	mainLayout.removeViewAt( MATCH_LIST_VIEW_INDEX );
			//}
			matchListLayout.addView( view );
			matchListInitialized = true;
			
			// Hide the refreshing animation
			ImageView anim = (ImageView)findViewById( R.id.refreshingAnim );
			if( anim != null ) {
				anim.setVisibility( View.INVISIBLE );
			}
		}
		
		activeMatchCount = matchListTask.getActiveMatchCount();
		finishedMatchCount = matchListTask.getFinishedMatchCount();
		
		startNewBtn.setVisibility( View.VISIBLE );
		settingsBtn.setVisibility( View.VISIBLE );
		tokensBtn.setVisibility( View.VISIBLE );
		loadingImg.setVisibility( View.GONE );
		
		Player thisPlayer = GetAClueApp.getThisPlayer();
		if( thisPlayer != null && thisPlayer.showAds() == true ) {
			removeAdsBtn.setVisibility( View.VISIBLE );
		}
		
		setHighScore();
		setTokenText( false );
		
		if( progress != null && progress.isShowing() ) {
			progress.dismiss();
		}
		
		// Cache the friends list
		//GetAClueApp.refreshFriendsListCache();
	}
	
	@Override
	public void onActivityResult( int requestCode, int resultCode, Intent data ) {
		super.onActivityResult( requestCode, resultCode, data );
		
		switch( requestCode ) {
			case LOGIN_ACTIVITY_REQUEST: {
				if( resultCode == -1 ) {
					finish();
				}
				else {
					init();
				}
				break;
			}
			case MATCH_ACTIVITY_REQUEST: {
				if( resultCode == DELETE_MATCH_RESULT_CODE ) {
					// Delete the last clicked match
					if( lastMatchClicked != null ) {
						DeleteMatchAsyncTask task = new DeleteMatchAsyncTask( lastMatchClicked.getMatch() );
						task.execute();
					}
				}
				
				setTokenText( false );
			}
			case NEW_MATCH_ACTIVITY_REQUEST:
			case MATCH_SUMMARY_ACTIVITY_REQUEST: {
				setTokenText( false );
				break;
			}
			case FB_ACTIVITY_REQUEST: {
				facebook.authorizeCallback( requestCode, resultCode, data );
				break;
			}
			case SETTINGS_ACTIVITY_REQUEST: {
				if( resultCode == REINIT_RESULT_CODE ) {
					new LogoutTask().execute();
				}
				break;
			}
			case GET_TOKENS_ACTIVITY_REQUEST: {
				if( resultCode == REFRESH_TOKENS_WITH_ANIM_RESULT_CODE ) {
					setTokenText( true );
				}
				else {
					setTokenText( false );
				}
			}
			case ADD_FREE_UPGRADE_ACTIVITY_REQUEST: {
				if( resultCode == AD_FREE_UPGRADE_PURCHASED_RESULT_CODE ) {
					Player thisPlayer = GetAClueApp.getThisPlayer();
					if( thisPlayer != null && !thisPlayer.showAds() ) {
						if( adView != null ) {
							adView.stopLoading();
							LinearLayout layout = (LinearLayout)findViewById( R.id.mainLayout );
							layout.removeView( adView );
							adView = null;
						}
						
						removeAdsBtn.setVisibility( View.GONE );
						
						AlertDialog.Builder dialog = new AlertDialog.Builder( this );
						dialog.setMessage( "Thank you for your purchase.\nAds will no longer be displayed in the game." );
						dialog.setNeutralButton( "Ok", null );
						dialog.setCancelable( false );
						dialog.show();
					}
				}
			}
		}
		
		haltClicks = false;
	}
	
	private void postLogin() {
		try {
			initAds();
			setTokenText( false );
			refreshMatchList();
			
			AppRater.appLaunched( this );
		}
		catch( Exception e ) {
			onNetworkError( e );
		}
	}
	
	public void onMatchClick( View view ) {
		if( !haltClicks ) {
			haltClicks = true;
			
			GetAClueApp.playSound( SoundManager.SOUND_MATCH_CLICK );
			
			lastMatchClicked = (MatchListItemBase)view;
			lastMatchClicked.showBusyIndicator();
			
			// Clear any notifications
			NotificationManager notificationManager = (NotificationManager)getSystemService( Context.NOTIFICATION_SERVICE );
			notificationManager.cancel( GetAClueApp.APP_NOTIFICATION_ID );
			
			// Always update the match from the server before opening the 
			// match activity just in case it was changed by the either player
			// and it has not refreshed in the list yet.
			new MatchUpdateTask().execute();
		}
	}
	
	private class MatchUpdateTask extends AsyncTask<Void, Void, Void> {
		private Exception e = null;
		private boolean networkError = false;
		
		@Override
		protected void onPreExecute() {
			
		}
		
		@Override
		protected Void doInBackground( Void... arg0 ) {
			Bundle ret = new Bundle();
			
			Log.i( LOG_TAG, "Updating match from match list" );
			
			try {
				lastMatchClicked.setMatch( MatchTwoPlayer.loadFromId( lastMatchClicked.getMatchId(), true, true ) );
			}
			catch( IOException e ) {
				ret.putBoolean( "networkError", true );
			}
			catch( WebServiceException e ) {
				ret.putBoolean( "networkError", true );
			}
			catch( Exception e ) {
				Log.e( LOG_TAG, "Unknown exception" );
				Log.e( LOG_TAG, Log.getStackTraceString( e ) );
			}
			
			return null;
		}
		
		@Override
		protected void onPostExecute( Void param ) {
			if( networkError ) {
				onNetworkError( e );
				return;
			}
			
			// TODO: Not real sure this is necessary
			//lastMatchClicked.refresh();
			
			PlayerAction action = lastMatchClicked.getMatch().getThisPlayerAction();
			if( action == PlayerAction.FINISHED_SUMMARY ) {
				// Show the match summary
				Intent myIntent = new Intent( MainActivity.this, MatchSummaryActivity.class );
				myIntent.putExtra( "Match", lastMatchClicked.getMatch() );
				startActivityForResult( myIntent, MATCH_SUMMARY_ACTIVITY_REQUEST );
			}
			else {
				// Start the Match activity passing the Match object to the activity
				Intent myIntent = new Intent( MainActivity.this, MatchActivity.class );
				myIntent.putExtra( "Match", lastMatchClicked.getMatch() );
				startActivityForResult( myIntent, MATCH_ACTIVITY_REQUEST );
			}
		}
	}
	
	private class DeleteMatchAsyncTask extends AsyncTask<Void,Void,Void> {
		private boolean networkError = false;
		private Exception e = null;
		private MatchTwoPlayer match = null;
		
		public DeleteMatchAsyncTask( MatchTwoPlayer match ) {
			this.match = match;
		}
		
		@Override
		protected void onPreExecute() {
			
		}
		
		@Override
		protected Void doInBackground( Void... params ) {
			try {
				if( match != null ) {
					WebServiceAdapter wsa = GetAClueApp.getWSAdapter();
					wsa.deleteMatch( match );
				}
			}
			catch( IOException e ) {
				networkError = true;
				this.e = e;
			}
			catch( WebServiceException e ) {
				networkError = true;
				this.e = e;
			}
			catch( Exception e ) {
				Log.e( LOG_TAG, "Unknown exception" );
				Log.e( LOG_TAG, Log.getStackTraceString( e ) );
			}
			
			return null;
		}
		
		@Override
		protected void onPostExecute( Void voids ) {
			if( networkError ) {
				onNetworkError( e );
				return;
			}
			
			refreshMatchList();
		}
	}
	
	public void onNewMatchClick( View view ) {
		if( !haltClicks ) {
			if( activeMatchCount >= MAX_ACTIVE_MATCHES ) {
				AlertDialog.Builder dialog = new AlertDialog.Builder( this );
				dialog.setMessage( "You already have " + activeMatchCount + " active matches\n\nPlease finsh or resign a match before starting a new one.\n(Long press on a match to resign)" );
				dialog.setNeutralButton( "Ok", null );
				dialog.show();
			}
			else {
				GetAClueApp.playSound( SoundManager.SOUND_BUTTON );
				Intent myIntent = new Intent( getApplicationContext(), NewMatchActivity.class );
				startActivityForResult( myIntent, NEW_MATCH_ACTIVITY_REQUEST );
				haltClicks = true;
			}
		}
	}
	
	private void setFonts() {
		Typeface tf = Typeface.createFromAsset( getAssets(), "fonts/vaground2.ttf" );
		
		Button btn = (Button)findViewById( R.id.startNewMatchButton );
		btn.setTypeface( tf );
		
		btn = (Button)findViewById( R.id.settingsButton );
		btn.setTypeface( tf );
		
		btn = (Button)findViewById( R.id.getTokensButton );
		btn.setTypeface( tf );
		
		btn = (Button)findViewById( R.id.removeAdsButton );
		btn.setTypeface( tf );
		
		TextView tv = (TextView)findViewById( R.id.totalCoins );
		tv.setTypeface( tf );
		
		tv = (TextView)findViewById( R.id.highScoreText );
		tv.setTypeface( tf );
		
		tv = (TextView)findViewById( R.id.highScore );
		tv.setTypeface( tf );
		
		tv = (TextView)findViewById( R.id.highScorePlayer );
		tv.setTypeface( tf );
	}
	
	// This is used by facebook authorize but I'm not sure why
	private final class LoginDialogListener implements DialogListener {
		public void onComplete( Bundle values ) {
			SessionEvents.onLoginSuccess();
		}
		
		public void onFacebookError( FacebookError error ) {
			SessionEvents.onLoginError( error.getMessage() );
		}
		
		public void onError( DialogError error ) {
			SessionEvents.onLoginError( error.getMessage() );
		}
		
		public void onCancel() {
			SessionEvents.onLoginError( "Action Canceled" );
		}
	}
	
	public class FacebookAuthListener implements AuthListener {
		public void onAuthSucceed() {
			new AsyncTask<Void,Void,Void>() {
				Exception e = null;
				
				@Override
				protected Void doInBackground( Void... arg0 ) {
					try {
						// This should never get called from the UI thread
						SessionStore.save( facebook, getApplicationContext() );
						Player thisPlayer = Player.fromFacebookId( "me", true );
						GetAClueApp.setThisPlayer( thisPlayer );
						return null;
					}
					catch( Exception e ) {
						this.e = e;
					}
					
					return null;
				}
				
				@Override
				protected void onPostExecute( Void voids ) {
					if( e != null ) {
						onNetworkError( e );
						return;
					}
					
					postLogin();
				}
			}.execute();
		}
		
		public void onAuthFail( String error ) {
			SessionStore.clear( getApplicationContext() );
			Editor editor = GetAClueApp.getPrefs().edit();
			editor.putInt( "LoginMode", LOGIN_MODE_NOT_SET );
			editor.commit();
			//fbAuthFailed = true;
			//fbAuthError = error;
			init();
		}
	}
	
	private class LogoutTask extends AsyncTask<Void,Void,Void> {
		Exception e = null;
		
		@Override
		protected void onPreExecute() {
			progress = ProgressDialog.show( MainActivity.this, "", "Loging out...", true );
		}
		
		@Override
		protected Void doInBackground( Void... arg0 ) {
			try {
				Player thisPlayer = GetAClueApp.getThisPlayer();
				if( thisPlayer == null ) {
					Log.i( LOG_TAG, "Loggin out" );
				}
				else {
					Log.i( LOG_TAG, "Loggin out: " + thisPlayer.getFullName() );
				}
				
				facebook.logout( MainActivity.this );
				
				if( thisPlayer != null ) {
					thisPlayer.unregisterGCM();
				}
			}
			catch( Exception e ) {
				this.e = e;
			}
			return null;
		}
		
		@Override
		protected void onPostExecute( Void param ) {
			if( progress != null && progress.isShowing() ) {
				progress.dismiss();
			}
			
			if( e != null ) {
				onNetworkError( e );
				return;
			}
			
			GetAClueApp.setThisPlayer( null );
			SessionStore.clear( getApplicationContext() );
			Editor editor = GetAClueApp.getPrefs().edit();
			editor.putInt( "LoginMode", LOGIN_MODE_NOT_SET );
			editor.commit();
			init();
		}
	}
	
	// TODO: This never seems to get called
	public class FacebookLogoutListener implements LogoutListener {
		public void onLogoutBegin() {
			
		}
		
		public void onLogoutFinish() {
			SessionStore.clear( getApplicationContext() );
			Editor editor = GetAClueApp.getPrefs().edit();
			editor.putInt( "LoginMode", LOGIN_MODE_NOT_SET );
			editor.commit();
			init();
		}
	}
	
	public void onNetworkError( final Exception e ) {
		if( !showingNetworkError ) {
			showingNetworkError = true;
			
			if( e != null ) {
				Log.e( LOG_TAG, Log.getStackTraceString( e ) );
			}
			else {
				Log.e( LOG_TAG, "Network error occured." );
			}
			
			if( progress != null && progress.isShowing() ) {
				progress.dismiss();
			}
			
			AlertDialog.Builder networkErrorDialog = new AlertDialog.Builder( this );
			if( !GetAClueApp.DEBUG_SEND_LOG || e instanceof UnknownHostException || e instanceof HttpHostConnectException ) {
				networkErrorDialog.setTitle( "Could not connect" );
				networkErrorDialog.setMessage( "Could not connect to server. Would you like to try again?" );
				networkErrorDialog.setPositiveButton( "Yes", new DialogInterface.OnClickListener() {
					public void onClick( DialogInterface arg0, int arg1 ) {
						showingNetworkError = false;
						haltClicks = false;
						if( !matchListInitialized ) {
							init();
						}
						else {
							refreshMatchList();
						}
					}
				} );
				networkErrorDialog.setNegativeButton( "No", new DialogInterface.OnClickListener() {
					public void onClick( DialogInterface arg0, int arg1 ) {
						finish();
					}
				} );
				networkErrorDialog.setCancelable( false );
				networkErrorDialog.show();
			}
			else {
				networkErrorDialog.setMessage( "Something bad happened! Send me the log." );
				networkErrorDialog.setPositiveButton( "Send Email", new DialogInterface.OnClickListener() {
					@Override
					public void onClick( DialogInterface arg0, int arg1 ) {
						showingNetworkError = false;
						haltClicks = false;
						
						// Get the log
						String lineSep = System.getProperty( "line.separator" );
						String msg = "From " + GetAClueApp.getThisPlayer().getName();
						msg += lineSep + lineSep + getString( R.string.device_info_fmt, GetAClueApp.getAppVersionCode( MainActivity.this ),
								Build.MODEL, Build.VERSION.RELEASE, GetAClueApp.getFormattedKernelVersion(), Build.DISPLAY );
						msg += lineSep + lineSep + e.getMessage();
						msg += lineSep + lineSep + Log.getStackTraceString( e );
						
						// Send an email
						Intent i = new Intent( Intent.ACTION_SEND );
						i.setType( "message/rfc822" );
						i.putExtra( Intent.EXTRA_EMAIL, new String[] { "bobdog462@gmail.com" } );
						i.putExtra( Intent.EXTRA_SUBJECT, "Get a Clue Debug Log: " + GetAClueApp.getThisPlayer().getName() );
						i.putExtra( Intent.EXTRA_TEXT, msg );
						try {
							startActivity( Intent.createChooser( i, "Send mail..." ) );
						}
						catch( android.content.ActivityNotFoundException ex ) {
							Toast.makeText( MainActivity.this, "Couldn't find an email client.\nCan't send email.", Toast.LENGTH_SHORT ).show();
						}
						
						
						// *** The following only works for rooted devices but provides a more complete log (logcat output) ***
						//Intent intent = new Intent( getApplicationContext(), SendLogActivity.class );
						//startActivity( intent );
						
						finish();
					}
				} );
				networkErrorDialog.setNegativeButton( "Cancel", new DialogInterface.OnClickListener() {
					@Override
					public void onClick( DialogInterface dialog, int which ) {
						finish();
					}
				} );
				networkErrorDialog.setCancelable( false );
				networkErrorDialog.show();
			}
		}
	}
	
	private void setVersionText() {
		String version = "Version " + getResources().getString( R.string.version_release );
		
		TextView tv = (TextView)findViewById( R.id.versionText );
		tv.setText( version );
	}
	
	private void setServerHostText() {
		TextView tv = (TextView)findViewById( R.id.debugServerHost );
		if( GetAClueApp.SHOW_SERVER_HOST ) {
			String appType = "";
			switch( GetAClueApp.getAppType() ) {
				case LOCAL: appType = "LOCAL"; break;
				case DEV: appType = "DEV"; break;
				case PROD: appType = "PROD"; break;
			}
			
			tv.setText( GetAClueApp.getServerHost() + " " + appType );
		}
		else {
			tv.setVisibility( View.GONE );
		}
	}
	
	private void setTokenText( boolean animate ) {
		Typeface tf = Typeface.createFromAsset( getAssets(), "fonts/vaground2.ttf" );
		
		TextView tv = (TextView)findViewById( R.id.totalCoins );
		ImageView iv = (ImageView)findViewById( R.id.coinGraphic );
		if( GetAClueApp.getThisPlayer() != null ) {
			int newCoinCount = (Integer)GetAClueApp.getThisPlayer().getTotalTokens();
			tv.setVisibility( View.VISIBLE );
			tv.setTypeface( tf );
			iv.setVisibility( View.VISIBLE );
			if( animate ) {
				Util.doTokenChangeAnim( this, tv, Integer.toString( newCoinCount ) );
			}
			else {
				tv.setText( Integer.toString( newCoinCount ) );
			}
		}
		else {
			tv.setVisibility( View.INVISIBLE );
			iv.setVisibility( View.INVISIBLE );
		}
	}
	
	public void onGetTokens( View view ) {
		GetAClueApp.playSound( SoundManager.SOUND_BUTTON );
		Intent myIntent = new Intent( getApplicationContext(), GetTokensActivity.class );
		startActivityForResult( myIntent, GET_TOKENS_ACTIVITY_REQUEST );
	}
	
	public void onRemoveAds( View view ) {
		Player thisPlayer = GetAClueApp.getThisPlayer();
		if( thisPlayer != null && thisPlayer.showAds() ) {
			GetAClueApp.playSound( SoundManager.SOUND_BUTTON );
			Intent myIntent = new Intent( getApplicationContext(), AdFreeUpgradeActivity.class );
			startActivityForResult( myIntent, ADD_FREE_UPGRADE_ACTIVITY_REQUEST );
		}
		else {
			AlertDialog.Builder dialog = new AlertDialog.Builder( this );
			dialog.setMessage( "You have already purchased the ad-free upgrade." );
			dialog.setNeutralButton( "Ok", null );
			dialog.show();
		}
	}
	
	private void setHighScore() {
		Player thisPlayer = GetAClueApp.getThisPlayer();
		int highScore = thisPlayer.getHighScore();
		String playerName = thisPlayer.getHighScorePlayerName();
		
		if( highScore > 0 && playerName.compareTo( "" ) != 0 ) {
			TextView tv = (TextView)findViewById( R.id.highScore );
			tv.setText( Integer.toString( highScore ) );
			
			tv = (TextView)findViewById( R.id.highScorePlayer );
			tv.setText( "with " + playerName );
			
			// TODO: Need to revamp the high score into a player stats area
			//highScoreLayout.setVisibility( View.VISIBLE );
		}
	}
	
	public void onSettingsButton( View view ) {
		GetAClueApp.playSound( SoundManager.SOUND_BUTTON );
		
		Intent settingsIntent = new Intent( this, SettingsActivity.class );
		startActivityForResult( settingsIntent, SETTINGS_ACTIVITY_REQUEST );
	}
}








