const menuButton = document.querySelector(".menu-toggle");
const navLinks = document.querySelector(".nav-links");

if (menuButton && navLinks) {
  menuButton.addEventListener("click", () => {
    const isOpen = navLinks.classList.toggle("open");
    menuButton.setAttribute("aria-expanded", String(isOpen));
    menuButton.textContent = isOpen ? "x" : "Menu";
  });
}

document.querySelectorAll("form").forEach((form) => {
  form.addEventListener("submit", (event) => {
    event.preventDefault();

    const status = form.querySelector(".form-status");
    const submitButton = form.querySelector('button[type="submit"]');
    const name = form.querySelector('input[name="name"]')?.value || "";
    const email = form.querySelector('input[name="email"]')?.value || "";
    const message = form.querySelector("textarea")?.value || "";
    const emailJsConfig = window.EMAILJS_CONFIG || {};
    const hasEmailJsConfig = Boolean(
      window.emailjs &&
        emailJsConfig.publicKey &&
        emailJsConfig.serviceId &&
        emailJsConfig.templateId &&
        !String(emailJsConfig.publicKey).startsWith("YOUR_") &&
        !String(emailJsConfig.serviceId).startsWith("YOUR_") &&
        !String(emailJsConfig.templateId).startsWith("YOUR_")
    );

    const setStatus = (text, state = "") => {
      if (!status) return;
      status.textContent = text;
      status.className = state ? `form-status ${state}` : "form-status";
    };

    const setSending = (isSending) => {
      if (!submitButton) return;
      submitButton.disabled = isSending;
      submitButton.textContent = isSending ? "Sending..." : "Send Message";
    };

    if (!form.action || !form.action.endsWith("send-email.php")) {
      const mailtoSubject = encodeURIComponent("Website inquiry from U & I Consultancy");
      const mailtoBody = encodeURIComponent(`Name: ${name}\nEmail: ${email}\n\n${message}`);
      window.location.href = `mailto:info@uiconsultancy.com?subject=${mailtoSubject}&body=${mailtoBody}`;
      return;
    }

    setStatus("Sending message...");
    setSending(true);

    if (hasEmailJsConfig) {
      emailjs
        .sendForm(emailJsConfig.serviceId, emailJsConfig.templateId, form, {
          publicKey: emailJsConfig.publicKey
        })
        .then(() => {
          setStatus("Thank you. Your message has been sent.", "success");
          form.reset();
        })
        .catch((error) => {
          const detail = error?.text || error?.message || "EmailJS could not send the message.";
          setStatus(`${detail} Trying server email...`, "error");
          return sendWithServer(form, setStatus);
        })
        .finally(() => {
          setSending(false);
        });
      return;
    }

    sendWithServer(form, setStatus).finally(() => {
      setSending(false);
    });
  });
});

function sendWithServer(form, setStatus) {
  return fetch(form.action, {
    method: "POST",
    body: new FormData(form),
    headers: {
      Accept: "application/json"
    }
  })
    .then(async (response) => {
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) {
        throw new Error(data.error || "Message could not be sent.");
      }
      setStatus("Thank you. Your message has been sent.", "success");
      form.reset();
    })
    .catch((error) => {
      setStatus(
        `${error.message} Please email us directly at info@uiconsultancy.com.`,
        "error"
      );
    });
}
