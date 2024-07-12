package com.morinda.morindastore;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import android.content.Intent;
import android.os.AsyncTask;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.View.OnClickListener;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.CompoundButton;
import android.widget.TextView;
import android.widget.CompoundButton.OnCheckedChangeListener;
import android.widget.EditText;
import android.widget.ProgressBar;
import android.widget.Spinner;
import android.widget.Toast;

public class CheckoutActivity extends ActionBarActivityEx {
	
	@Override
	public void onCreate( Bundle savedInstanceState ) {
		super.onCreate( savedInstanceState );
		setContentView( R.layout.activity_checkout );
		
		((Button)findViewById( R.id.continueBtn )).setTypeface( robotoMed );
		((TextView)findViewById( R.id.titleText )).setTypeface( robotoMed );
		((TextView)findViewById( R.id.customerTxt )).setTypeface( robotoMed );
		((TextView)findViewById( R.id.billingTxt )).setTypeface( robotoMed );
		((TextView)findViewById( R.id.shippingTxt )).setTypeface( robotoMed );
		
		hideShoppingCartMenuItem();
		
		ArrayAdapter<CharSequence> adapter = ArrayAdapter.createFromResource( this, R.array.states_array, android.R.layout.simple_spinner_item );
		adapter.setDropDownViewResource( android.R.layout.simple_spinner_dropdown_item );
		
		Spinner bStateSpinner = (Spinner)findViewById( R.id.bState );
		bStateSpinner.setAdapter( adapter );
		
		Spinner sStateSpinner = (Spinner)findViewById( R.id.sState );
		sStateSpinner.setAdapter( adapter );
		
		Button contBtn = (Button)findViewById( R.id.continueBtn );
		contBtn.setOnClickListener( new OnClickListener() {
			@Override
			public void onClick( View v ) {
				if( validate() ) {
					// Show spinner
					ProgressBar spinner = (ProgressBar)findViewById( R.id.loadingSpinner );
					spinner.setVisibility( View.VISIBLE );
					
					setResult( ShoppingCartActivity.RESULT_CONTINUE );
					finish();
				}
			}
		});
		
		CheckBox cb = (CheckBox)findViewById( R.id.shippingSameAsBillingCheck );
		cb.setOnCheckedChangeListener( new OnCheckedChangeListener() {
			@Override
			public void onCheckedChanged( CompoundButton buttonView, boolean isChecked ) {
				if( isChecked ) {
					boolean valid = true;
					LinkedHashMap<String, String> billingInfo = getBillingInfo();
					for( Map.Entry<String, String> entry : billingInfo.entrySet() ) {
						if( entry.getKey() != "ADDRESS2" && entry.getValue().equals( "" ) ) {
							String msg = "Please complete all billing info first";
							Toast toast = Toast.makeText( getApplicationContext(), msg, Toast.LENGTH_LONG );
							toast.setGravity( Gravity.CENTER_VERTICAL, 0, -50 );
							toast.show();
							valid = false;
							break;
						}
					}
					
					if( valid ) {
						EditText editFrom;
						EditText editTo;
						
						editFrom = (EditText)findViewById( R.id.bFirstName );
						editTo = (EditText)findViewById( R.id.sFirstName );
						editTo.setText( editFrom.getText().toString().trim() );
						
						editFrom = (EditText)findViewById( R.id.bLastName );
						editTo = (EditText)findViewById( R.id.sLastName );
						editTo.setText( editFrom.getText().toString().trim() );
						
						editFrom = (EditText)findViewById( R.id.bAddress1 );
						editTo = (EditText)findViewById( R.id.sAddress1 );
						editTo.setText( editFrom.getText().toString().trim() );
						
						editFrom = (EditText)findViewById( R.id.bAddress2 );
						editTo = (EditText)findViewById( R.id.sAddress2 );
						editTo.setText( editFrom.getText().toString().trim() );
						
						editFrom = (EditText)findViewById( R.id.bCity );
						editTo = (EditText)findViewById( R.id.sCity );
						editTo.setText( editFrom.getText().toString().trim() );
						
						Spinner spinnerFrom = (Spinner)findViewById( R.id.bState );
						Spinner spinnerTo = (Spinner)findViewById( R.id.sState );
						spinnerTo.setSelection( spinnerFrom.getSelectedItemPosition() );
						
						editFrom = (EditText)findViewById( R.id.bZip );
						editTo = (EditText)findViewById( R.id.sZip );
						editTo.setText( editFrom.getText().toString().trim() );
					}
					else {
						buttonView.setChecked( false );
					}
				}
				else {
					EditText edit;
					
					edit = (EditText)findViewById( R.id.sFirstName );
					edit.setText( "" );
					
					edit = (EditText)findViewById( R.id.sLastName );
					edit.setText( "" );
					
					edit = (EditText)findViewById( R.id.sAddress1 );
					edit.setText( "" );
					
					edit = (EditText)findViewById( R.id.sAddress2 );
					edit.setText( "" );
					
					edit = (EditText)findViewById( R.id.sCity );
					edit.setText( "" );
					
					Spinner spinner = (Spinner)findViewById( R.id.sState );
					spinner.setSelection( 0 );
					
					edit = (EditText)findViewById( R.id.sZip );
					edit.setText( "" );
				}
			}
		});
		
		ShoppingCart cart = ShoppingCart.getInstance();
		setCustomerInfo( cart );
		setBillingInfo( cart );
		setShippingInfo( cart );
	}
	
	private boolean validate() {
		String msg = "";
		
		// Validate customer info
		LinkedHashMap<String, String> custInfo = getCustomerInfo();
		for( Map.Entry<String, String> entry : custInfo.entrySet() ) {
			if( entry.getValue().equals( "" ) ) {
				msg = entry.getKey() + " is required";
				break;
			}
			
			if( entry.getKey().equals( "PHONE" ) ) {
				if( entry.getValue().length() != 10 ) {
					msg = "PHONE NUMBER is invalid, must be 10 digits including area code";
					break;
				}
			}
			
			if( entry.getKey().equals( "EMAIL" ) ) {
				if( !entry.getValue().contains( "@" ) || !entry.getValue().contains( "." ) ) {
					msg = entry.getKey() + " is not a valid email address";
					break;
				}
			}
		}
		
		// Validate billing info
		LinkedHashMap<String, String> billingInfo = getBillingInfo();
		for( Map.Entry<String, String> entry : billingInfo.entrySet() ) {
			if( entry.getKey() != "ADDRESS2" && entry.getValue().equals( "" ) ) {
				msg = "Billing " + entry.getKey() + " is required";
				break;
			}
		}
		
		// Validate shipping info
		LinkedHashMap<String, String> shippingInfo = getShippingInfo();
		for( Map.Entry<String, String> entry : shippingInfo.entrySet() ) {
			if( entry.getKey() != "SHIPADDRESS2" && entry.getValue().equals( "" ) ) {
				msg = "Shipping " + entry.getKey() + " is required";
				break;
			}
		}
		
		// Show any invalids
		if( msg != "" ) {
			Toast toast = Toast.makeText( getApplicationContext(), msg, Toast.LENGTH_LONG );
			toast.setGravity( Gravity.CENTER_VERTICAL, 0, -50 );
			toast.show();
			return false;
		}
		
		// Set info in cart
		ShoppingCart cart = ShoppingCart.getInstance();
		cart.setCustomerInfo( custInfo );
		cart.setBillingInfo( billingInfo );
		cart.setShippingInfo( shippingInfo );
		
		return true;
	}
	
	private LinkedHashMap<String, String> getCustomerInfo() {
		LinkedHashMap<String, String> custInfo = new LinkedHashMap<String, String>();
		
		EditText edit;
		
		edit = (EditText)findViewById( R.id.ipcNumber );
		custInfo.put( "IPC", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.phoneNumber );
		String phone = edit.getText().toString().trim().replace( "-", "" );
		custInfo.put( "PHONE", phone );
		
		edit = (EditText)findViewById( R.id.email );
		custInfo.put( "EMAIL", edit.getText().toString().trim() );
		
		return custInfo;
	}
	
	private void setCustomerInfo( ShoppingCart cart ) {
		LinkedHashMap<String, String> custInfo = cart.getCustomerInfo();
		if( custInfo != null ) {
			if( custInfo.containsKey( "IPC" ) ) {
				EditText edit = (EditText)findViewById( R.id.ipcNumber );
				edit.setText( custInfo.get( "IPC" ) );
			}
			
			if( custInfo.containsKey( "PHONE" ) ) {
				EditText edit = (EditText)findViewById( R.id.phoneNumber );
				edit.setText( custInfo.get( "PHONE" ) );
			}
			
			if( custInfo.containsKey( "EMAIL" ) ) {
				EditText edit = (EditText)findViewById( R.id.email );
				edit.setText( custInfo.get( "EMAIL" ) );
			}
		}
	}
	
	private LinkedHashMap<String, String> getBillingInfo() {
		LinkedHashMap<String, String> billingInfo = new LinkedHashMap<String, String>();
		
		EditText edit;
		
		edit = (EditText)findViewById( R.id.bFirstName );
		billingInfo.put( "FIRSTNAME", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.bLastName );
		billingInfo.put( "LASTNAME", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.bAddress1 );
		billingInfo.put( "ADDRESS1", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.bAddress2 );
		billingInfo.put( "ADDRESS2", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.bCity );
		billingInfo.put( "CITY", edit.getText().toString().trim() );
		
		Spinner spinner = (Spinner)findViewById( R.id.bState );
		if( spinner.getSelectedItem() != null ) {
			billingInfo.put( "STATE", spinner.getSelectedItem().toString().trim() );
		}
		else {
			billingInfo.put( "STATE", "" );
		}
		
		edit = (EditText)findViewById( R.id.bZip );
		billingInfo.put( "ZIP", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.phoneNumber );
		billingInfo.put( "PHONE", edit.getText().toString() );
		
		return billingInfo;
	}
	
	private void setBillingInfo( ShoppingCart cart ) {
		LinkedHashMap<String, String> billingInfo = cart.getBillingInfo();
		if( billingInfo != null ) {
			if( billingInfo.containsKey( "FIRSTNAME" ) ) {
				EditText edit = (EditText)findViewById( R.id.bFirstName );
				edit.setText( billingInfo.get( "FIRSTNAME" ) );
			}
			
			if( billingInfo.containsKey( "LASTNAME" ) ) {
				EditText edit = (EditText)findViewById( R.id.bLastName );
				edit.setText( billingInfo.get( "LASTNAME" ) );
			}
			
			if( billingInfo.containsKey( "ADDRESS1" ) ) {
				EditText edit = (EditText)findViewById( R.id.bAddress1 );
				edit.setText( billingInfo.get( "ADDRESS1" ) );
			}
			
			if( billingInfo.containsKey( "ADDRESS2" ) ) {
				EditText edit = (EditText)findViewById( R.id.bAddress2 );
				edit.setText( billingInfo.get( "ADDRESS2" ) );
			}
			
			if( billingInfo.containsKey( "CITY" ) ) {
				EditText edit = (EditText)findViewById( R.id.bCity );
				edit.setText( billingInfo.get( "CITY" ) );
			}
			
			if( billingInfo.containsKey( "STATE" ) ) {
				List<String> states = Arrays.asList( getResources().getStringArray( R.array.states_array ) );
				Spinner spinner = (Spinner)findViewById( R.id.bState );
				spinner.setSelection( states.indexOf( billingInfo.get( "STATE" ) ) );
			}
			
			if( billingInfo.containsKey( "ZIP" ) ) {
				EditText edit = (EditText)findViewById( R.id.bZip );
				edit.setText( billingInfo.get( "ZIP" ) );
			}
		}
	}
	
	private LinkedHashMap<String, String> getShippingInfo() {
		LinkedHashMap<String, String> shippingInfo = new LinkedHashMap<String, String>();
		
		EditText edit;
		
		edit = (EditText)findViewById( R.id.sFirstName );
		String shipName = edit.getText().toString().trim();
		edit = (EditText)findViewById( R.id.sLastName );
		shipName += ' ' + edit.getText().toString().trim();
		shippingInfo.put( "SHIPNAME", shipName );
		
		edit = (EditText)findViewById( R.id.sAddress1 );
		shippingInfo.put( "SHIPADDRESS1", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.sAddress2 );
		shippingInfo.put( "SHIPADDRESS2", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.sCity );
		shippingInfo.put( "SHIPCITY", edit.getText().toString().trim() );
		
		Spinner spinner = (Spinner)findViewById( R.id.sState );
		if( spinner.getSelectedItem() != null ) {
			shippingInfo.put( "SHIPSTATE", spinner.getSelectedItem().toString().trim() );
		}
		else {
			shippingInfo.put( "SHIPSTATE", "" );
		}
		
		edit = (EditText)findViewById( R.id.sZip );
		shippingInfo.put( "SHIPZIP", edit.getText().toString().trim() );
		
		edit = (EditText)findViewById( R.id.phoneNumber );
		shippingInfo.put( "SHIPPHONE", edit.getText().toString() );
		
		return shippingInfo;
	}
	
	private void setShippingInfo( ShoppingCart cart ) {
		LinkedHashMap<String, String> shippingInfo = cart.getShippingInfo();
		if( shippingInfo != null ) {
			if( shippingInfo.containsKey( "SHIPNAME" ) ) {
				String[] name = shippingInfo.get( "SHIPNAME" ).split( " " );
				EditText edit = null;
				if( name.length >= 1 ) {
					edit = (EditText)findViewById( R.id.sFirstName );
					edit.setText( name[ 0 ] );
				}
				if( name.length >= 2 ) {
					edit = (EditText)findViewById( R.id.sLastName );
					edit.setText( name[ 1 ] );
				}
			}
			
			if( shippingInfo.containsKey( "SHIPADDRESS1" ) ) {
				EditText edit = (EditText)findViewById( R.id.sAddress1 );
				edit.setText( shippingInfo.get( "SHIPADDRESS1" ) );
			}
			
			if( shippingInfo.containsKey( "SHIPADDRESS2" ) ) {
				EditText edit = (EditText)findViewById( R.id.sAddress2 );
				edit.setText( shippingInfo.get( "SHIPADDRESS2" ) );
			}
			
			if( shippingInfo.containsKey( "SHIPCITY" ) ) {
				EditText edit = (EditText)findViewById( R.id.sCity );
				edit.setText( shippingInfo.get( "SHIPCITY" ) );
			}
			
			if( shippingInfo.containsKey( "SHIPSTATE" ) ) {
				List<String> states = Arrays.asList( getResources().getStringArray( R.array.states_array ) );
				Spinner spinner = (Spinner)findViewById( R.id.sState );
				spinner.setSelection( states.indexOf( shippingInfo.get( "SHIPSTATE" ) ) );
			}
			
			if( shippingInfo.containsKey( "SHIPZIP" ) ) {
				EditText edit = (EditText)findViewById( R.id.sZip );
				edit.setText( shippingInfo.get( "SHIPZIP" ) );
			}
		}
	}
}















