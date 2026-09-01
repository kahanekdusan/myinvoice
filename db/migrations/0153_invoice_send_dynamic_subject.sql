-- Starší uložený default přepisuje typově správný předmět z
-- InvoiceEmailVarsBuilder (např. cenovou nabídku označí jako fakturu).
-- Měníme jen původní výchozí hodnoty; vlastní předměty zůstanou zachované.
UPDATE email_templates
SET subject = '{{ subject }}'
WHERE code = 'invoice_send'
  AND (
      (locale = 'cs' AND subject = 'Faktura {{ invoice.varsymbol }}')
      OR (locale = 'en' AND subject = 'Invoice {{ invoice.varsymbol }}')
  );
