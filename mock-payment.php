<?php
// Claude, var svårt att hitta info om hur man gör
$scenario = $_GET['scenario'] ?? '';

switch ( $scenario ) {
	case 'approved':
		header( 'Content-Type: application/json' );
		echo json_encode(
			array(
				'status'         => 'approved',
				'transaction_id' => 'TX' . rand( 1000, 9999 ),
			)
		);
		break;
	case 'declined':
		header( 'Content-Type: application/json' );
		echo json_encode(
			array(
				'status' => 'declined',
				'reason' => 'insufficient_funds',
			)
		);
		break;
	case 'pending':
		header( 'Content-Type: application/json' );
		echo json_encode( array( 'status' => 'pending' ) );
		break;
	case 'error500':
		http_response_code( 500 );
		echo 'Internal Server Error';
		break;
	case 'error400':
		http_response_code( 400 );
		echo 'Bad Request';
		break;
	case 'badjson':
		header( 'Content-Type: application/json' );
		echo '{ "status": "approved", "transaction_id": "TX1234" '; // Missing closing brace
		break;
	case 'timeout':
		sleep( 20 ); // längre än gatewayens 15 sekunder
		header( 'Content-Type: application/json' );
		echo json_encode(
			array(
				'status'         => 'approved',
				'transaction_id' => 'TX' . random_int( 1000, 9999 ),
			)
		);
		break;
	default:
		http_response_code( 400 );
		echo 'Invalid scenario';
		break;
}
