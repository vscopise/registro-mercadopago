
(function () {
  'use strict'
  const mercadoPagoPublicKey = document.getElementById("mercado-pago-public-key").value;
  const mercadopago = new MercadoPago(mercadoPagoPublicKey);
  let cardPaymentBrickController;

  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault()
        event.stopPropagation()
        if (form.checkValidity()) {
          $('.container_checkout').fadeOut(500);
          loadPaymentForm();
          setTimeout(() => { $('.container_payment').show(500).fadeIn(); }, 500);
        }
        form.classList.add('was-validated')
      }, false)
    });

  async function loadPaymentForm() {
    var name = document.getElementById('name').value;
    document.getElementById('name_text').textContent = name;

    var docNumber = document.getElementById('docNumber').value;
    document.getElementById('docNumber_text').textContent = docNumber;

    var phone = document.getElementById('phone').value;
    document.getElementById('phone_text').textContent = phone;

    var amount = document.getElementById('amount').value;
    document.getElementById('amount_text').textContent = '$' + amount;

    //Instanciar bricks
    const productCost = document.getElementById('amount').value;

    const settings = {
      initialization: {
        amount: productCost,
      },
      callbacks: {
        onReady: () => {
          console.log('brick ready')
        },
        onError: (error) => {
          alert(JSON.stringify(error))
        },
        onSubmit: (cardFormData) => {
          proccessPayment(cardFormData)
        }
      },
      locale: 'es',
      customization: {
        paymentMethods: {
          maxInstallments: 5
        },
        visual: {
          style: {
            theme: 'bootstrap',
            customVariables: {
              formBackgroundColor: '#f7f7f7',
            }
          }
        }
      },
    }

    const bricks = mercadopago.bricks();
    cardPaymentBrickController = await bricks.create('cardPayment', 'cardPaymentBrick_container', settings);
  }

  const proccessPayment = (cardFormData) => {
    fetch("/process_payment", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(cardFormData),
    })
      .then(response => {
        return response.json();
      })
      .then(result => {
        if (!result.hasOwnProperty("error_message")) {
          sheetApendData({
            id: result.id,
            email: cardFormData.payer.email,
            amount: cardFormData.transaction_amount,
          });
          document.getElementById("payment-id").innerText = result.id;
          document.getElementById("payment-status").innerText = result.status;
          document.getElementById("payment-detail").innerText = result.detail;
          $('.container_payment').fadeOut(500);
          setTimeout(() => { $('.container_result').show(500).fadeIn(); }, 500);
        } else {
          alert(JSON.stringify({
            status: result.status,
            message: result.error_message
          }))
        }
      })
      .catch(error => {
        alert("Unexpected error\n" + JSON.stringify(error));
      });
  }

  const sheetApendData = (dataToInsert) => {
    fetch("/append_data", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(dataToInsert),
    })
      .then(response => {
        //return response.json();
        document.getElementById("google-sheet-result").innerText = 'Registro agregado a la hoja de cálculo';
      })
    }
})();