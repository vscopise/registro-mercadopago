<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require __DIR__ . '/../vendor/autoload.php';

if ('127.0.0.1' == $_SERVER['SERVER_ADDR']) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../env/dev/')->load();
} else {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/../env/prod/')->load();
}

$app = AppFactory::create();

MercadoPago\SDK::setAccessToken($_ENV['MERCADOPAGO_ACCESS_TOKEN']);

$app->get('/', function (Request $request, Response $response, $args) {

    $loader = new FilesystemLoader(__DIR__ . '/../client');
    $twig = new Environment($loader);

    $response->getBody()->write($twig->render('index.html', ['public_key' => $_ENV['MERCADOPAGO_PUBLIC_KEY']]));
    return $response;
});

$app->post('/process_payment', function (Request $request, Response $response) {
    try {
        $contents = json_decode(file_get_contents('php://input'), true);
        $parsed_request = $request->withParsedBody($contents);
        $parsed_body = $parsed_request->getParsedBody();

        //Realizo el primer cobro
        $payment = new MercadoPago\Payment();
        $payment->transaction_amount = (float)$parsed_body['transaction_amount'];
        $payment->token = $parsed_body['token'];
        $payment->description = 'Registro en Mercadopago';
        $payment->installments = (int)$parsed_body['installments'];
        $payment->payment_method_id = $parsed_body['payment_method_id'];
        $payment->payer = array(
            'email' => $parsed_body['payer']['email']
        );

        $customer_id = search_customer($parsed_body["payer"]["email"]);

        $payment->save();

        if ('approved' === $payment->status) {

             //Me fijo si existe el customer
            /*$customer = new MercadoPago\Customer();
            $filters = array(
                "email" => $parsed_body["payer"]["email"]
            );
            $customers = MercadoPago\Customer::search($filters);

            if (0 == $customers->total) {
                //Si no existe creo un nuevo customer
                $customer = new MercadoPago\Customer();
                $customer->email = $parsed_body['payer']['email'];
                $customer->save();
                $customer_id = $customer->id;
            } else {
                $customer_id = $customers[0]->id;
                /* //elimino las tarjetas guardadas
                $customer = MercadoPago\Customer::find_by_id($customer_id);
                $cards = $customer->cards();
                foreach ($cards as $card) {

                } 
            } */

            //$customer_id = search_customer($parsed_body["payer"]["email"]);

            if (!$customer_id) {
                $customer = new MercadoPago\Customer();
                //$payer = $payment->payer;
                $customer->email = $parsed_body['payer']['email'];
                $customer->save();
                $customer_id = $customer->id;
            }

            //Guardo la nueva tarjeta
            $card = new MercadoPago\Card();
            $card->token = $payment->token;
            $card->customer_id = $customer_id;
            $card->save();
        }

        $response_fields = array(
            'status' => $payment->status,
            'status_detail' => $payment->status_detail,
            'id' => $payment->id,
        );

        $response_body = json_encode($response_fields);
        $response->getBody()->write($response_body);

        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    } catch (Exception $exception) {
        $response_fields = array('error_message' => $exception->getMessage());

        $response_body = json_encode($response_fields);
        $response->getBody()->write($response_body);

        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
});

$app->post('/append_data', function (Request $request, Response $response) {
    try {
        $contents = json_decode(file_get_contents('php://input'), true);

        $client = new Google_Client();
        $client->setApplicationName('Google Sheets and PHP');
        $client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setAuthConfig(__DIR__ . '/credentials.json');
        $service = new Google_Service_Sheets($client);
        $spreadsheetId = $_ENV['SPREADSHEET_ID'];

        date_default_timezone_set("America/Montevideo");

        $values = [
            $contents['id'],
            $contents['email'],
            $contents['amount'],
            'yes',
            date('Y-m-d H:i:s', time()),
        ];

        $sheet = "Suscriptores";
        $valueRange = new Google_Service_Sheets_ValueRange();
        $valueRange->setValues(["values" => $values]);
        $conf = ["valueInputOption" => "USER_ENTERED"];
        $result = $service->spreadsheets_values->append(
            $spreadsheetId,
            $sheet,
            $valueRange,
            $conf
        );

        $range = $result->updates->updatedRange;
        $cell = substr($range, strpos($range, ":") + 2);
        $valueRange = $sheet . "!F" . $cell . ":F" . $cell;
        $values = [
            ['=MID(E' . $cell . ';9;2)']
        ];
        $body = new Google_Service_Sheets_ValueRange([
            'values' => $values,
        ]);
        $result = $service->spreadsheets_values->update(
            $spreadsheetId,
            $valueRange,
            $body,
            $conf
        );

        return $result;
    } catch (Exception $exception) {
        $response_body = json_encode($response_fields);
        $response->getBody()->write($response_body);

        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
});

function search_customer($email)
{
    $customer = new MercadoPago\Customer();
    $filters = array(
        "email" => $email
    );
    $customers = MercadoPago\Customer::search($filters);
    if (0 == $customers->total) {
        return false;
    } else {
        return $customers[0]->id;
    }
}


$app->get('/{filetype}/{filename}', function (Request $request, Response $response, $args) {
    switch ($args['filetype']) {
        case 'css':
            $fileFolderPath = __DIR__ . '/../client/css/';
            $mimeType = 'text/css';
            break;

        case 'js':
            $fileFolderPath = __DIR__ . '/../client/js/';
            $mimeType = 'application/javascript';
            break;

        case 'img':
            $fileFolderPath = __DIR__ . '/../client/img/';
            $mimeType = 'image/png';
            break;

        default:
            $fileFolderPath = '';
            $mimeType = '';
    }

    $filePath = $fileFolderPath . $args['filename'];

    if (!file_exists($filePath)) {
        return $response->withStatus(404, 'File not found');
    }

    $newResponse = $response->withHeader('Content-Type', $mimeType . '; charset=UTF-8');
    $newResponse->getBody()->write(file_get_contents($filePath));

    return $newResponse;
});

$app->run();
