<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être un texte.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'email' => 'Indiquez une adresse email valide.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'confirmed' => 'La confirmation de :attribute ne correspond pas.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'exists' => 'La valeur sélectionnée pour :attribute est invalide.',
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'uuid' => 'La référence de validation est invalide. Rechargez la page.',
    'date_format' => 'Le champ :attribute doit respecter le format :format.',
    'after' => 'Le champ :attribute doit être postérieur à :date.',
    'after_or_equal' => 'Le champ :attribute doit être égal ou postérieur à :date.',
    'before_or_equal' => 'Le champ :attribute doit être égal ou antérieur à :date.',
    'min' => ['numeric' => 'Le champ :attribute doit être au moins :min.', 'string' => 'Le champ :attribute doit contenir au moins :min caractères.', 'array' => 'Choisissez au moins :min élément.'],
    'max' => ['numeric' => 'Le champ :attribute ne doit pas dépasser :max.', 'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.', 'file' => 'Le fichier :attribute ne doit pas dépasser :max Ko.'],
    'attributes' => ['name' => 'nom', 'phone' => 'téléphone', 'first_name' => 'prénom', 'password' => 'mot de passe', 'slot_id' => 'créneau de retrait', 'date' => 'date', 'starts_at' => 'heure de début', 'ends_at' => 'heure de fin', 'capacity_override' => 'capacité personnalisée', 'default_capacity' => 'capacité par défaut', 'price' => 'prix'],
];
