jQuery(function ($) {
  let emailCheckTimeout;
  const emailField = $("#billing_email");
  const invoiceEmailField = $("#billing_invoice_email");

  // Function to update payment methods
  function updatePaymentMethods(email, invoiceEmail) {
    if (!email && !invoiceEmail) return;

    $.ajax({
      url: wcEmailPayments.ajax_url,
      type: "POST",
      data: {
        action: "check_email_payment_methods",
        nonce: wcEmailPayments.nonce,
        email: email || "",
        billing_invoice_email: invoiceEmail || "",
      },
      success: function (response) {
        if (response.success && response.data.allowed_gateways) {
          // Hide all payment methods first
          $(".wc_payment_method").hide();

          // Show only allowed payment methods
          response.data.allowed_gateways.forEach(function (gatewayId) {
            $(`.wc_payment_method.payment_method_${gatewayId}`).show();
          });

          // If current selected payment method is not in allowed list, select the first allowed one
          const selectedMethod = $(
            'input[name="payment_method"]:checked'
          ).val();
          if (!response.data.allowed_gateways.includes(selectedMethod)) {
            $(
              `input[name="payment_method"][value="${response.data.allowed_gateways[0]}"]`
            )
              .prop("checked", true)
              .trigger("click");
          }
        }
      },
    });
  }

  // Function to handle email changes
  function handleEmailChange() {
    const email = emailField.val();
    const invoiceEmail = invoiceEmailField.val();

    // Clear existing timeout
    clearTimeout(emailCheckTimeout);

    // Set new timeout to prevent too many requests
    emailCheckTimeout = setTimeout(function () {
      updatePaymentMethods(email, invoiceEmail);
    }, 500);
  }

  // Check on email field changes
  emailField.on("change keyup", handleEmailChange);
  invoiceEmailField.on("change keyup", handleEmailChange);

  // Also check when page loads if emails are already filled
  if (emailField.val() || invoiceEmailField.val()) {
    updatePaymentMethods(emailField.val(), invoiceEmailField.val());
  }

  // Update when returning to page (browser back button)
  $(window).on("pageshow", function () {
    if (emailField.val() || invoiceEmailField.val()) {
      updatePaymentMethods(emailField.val(), invoiceEmailField.val());
    }
  });

  // If using checkout with AJAX enabled, also update on checkout update
  $(document.body).on("updated_checkout", function () {
    if (emailField.val() || invoiceEmailField.val()) {
      updatePaymentMethods(emailField.val(), invoiceEmailField.val());
    }
  });
});
