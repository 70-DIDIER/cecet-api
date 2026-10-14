{{ $subjectLine }}

Votre code : {{ $code }}

Ce code est valable {{ $validityMinutes >= 120 && $validityMinutes % 60 === 0 ? intdiv($validityMinutes, 60).' heures' : ($validityMinutes === 60 ? '1 heure' : $validityMinutes.' minute(s)') }} et ne peut être utilisé qu'une seule fois.
Ne le communiquez à personne : la CECT ne vous le demandera jamais par téléphone ou par message.

Si vous n'êtes pas à l'origine de cette demande, ignorez cet email et signalez-le à la CECT.
