/**
 * Invoice Ninja (https://invoiceninja.com)
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license 
 */class a{constructor(t,n){this.shouldDisplayTerms=t,this.shouldDisplaySignature=n,this.submitting=!1,this.steps=new Map,this.steps.set("rff",{element:document.getElementById("displayRequiredFieldsModal"),nextButton:document.getElementById("rff-next-step"),callback:()=>{const e={firstName:document.querySelector('input[name="rff_first_name"]'),lastName:document.querySelector('input[name="rff_last_name"]'),email:document.querySelector('input[name="rff_email"]'),city:document.querySelector('input[name="rff_city"]'),postalCode:document.querySelector('input[name="rff_postal_code"]')};e.firstName&&(document.querySelector('input[name="contact_first_name"]').value=e.firstName.value),e.lastName&&(document.querySelector('input[name="contact_last_name"]').value=e.lastName.value),e.email&&(document.querySelector('input[name="contact_email"]').value=e.email.value),e.city&&(document.querySelector('input[name="client_city"]').value=e.city.value),e.postalCode&&(document.querySelector('input[name="client_postal_code"]').value=e.postalCode.value)}}),this.shouldDisplaySignature&&this.steps.set("signature",{element:document.getElementById("displaySignatureModal"),nextButton:document.getElementById("signature-next-step"),boot:()=>this.signaturePad=new SignaturePad(document.getElementById("signature-pad"),{penColor:"rgb(0, 0, 0)"}),callback:()=>document.querySelector('input[name="signature"').value=this.signaturePad.toDataURL()}),this.shouldDisplayTerms&&this.steps.set("terms",{element:document.getElementById("displayTermsModal"),nextButton:document.getElementById("accept-terms-button")})}handleMethodSelect(t){document.getElementById("company_gateway_id").value=t.dataset.companyGatewayId,document.getElementById("payment_method_id").value=t.dataset.gatewayTypeId;const n=document.querySelector('input[name="contact_first_name"').value.length>=1&&document.querySelector('input[name="contact_last_name"').value.length>=1&&document.querySelector('input[name="contact_email"').value.length>=1&&document.querySelector('input[name="client_city"').value.length>=1&&document.querySelector('input[name="client_postal_code"').value.length>=1;if((t.dataset.isPaypal!="1"||n)&&this.steps.delete("rff"),this.steps.size===0)return this.submitForm();const e=this.steps.values().next().value;e.element.removeAttribute("style"),e.boot&&e.boot(),console.log(e),e.nextButton.addEventListener("click",()=>{e.element.setAttribute("style","display: none;"),this.steps=new Map(Array.from(this.steps.entries()).slice(1)),e.callback&&e.callback(),this.handleMethodSelect(t)})}submitForm(){this.submitting=!0,document.getElementById("payment-form").submit()}handle(){document.querySelectorAll(".dropdown-gateway-button").forEach(t=>{t.addEventListener("click",()=>{this.submitting||this.handleMethodSelect(t)})})}}const l=document.querySelector('meta[name="require-invoice-signature"]').content,o=document.querySelector('meta[name="show-invoice-terms"]').content;new a(!!+o,!!+l).handle();